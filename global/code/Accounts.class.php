<?php

/**
 * The Account class. Added in 2.3.0; will replace the old accounts.php file.
 */


// -------------------------------------------------------------------------------------------------

namespace FormTools;

use PDOException;


/**
 * Form Tools Accounts class.
 */
class Accounts {

    /**
     * Creates the administrator account. Used within the installation process.
     * @param array $info
     * @return array
     */
    public static function setAdminAccount(array $info)
    {
        $LANG = Core::$L;

        $rules = array();
        $rules[] = "required,first_name,{$LANG["validation_no_first_name"]}";
        $rules[] = "required,last_name,{$LANG["validation_no_last_name"]}";
        $rules[] = "required,email,{$LANG["validation_no_admin_email"]}";
        $rules[] = "valid_email,email,Please enter a valid administrator email address.";
        $rules[] = "required,username,{$LANG["validation_no_username"]}";
        $rules[] = "required,password,{$LANG["validation_no_password"]}";
        $rules[] = "required,password_2,{$LANG["validation_no_second_password"]}";
        $rules[] = "same_as,password,password_2,{$LANG["validation_passwords_different"]}";
        $errors = validate_fields($info, $rules);

        if (!empty($errors)) {
            return array(false, General::getErrorListHTML($errors));
        }

        $db = Core::$db;
        $db->query("
            UPDATE {PREFIX}accounts
            SET first_name = :first_name,
                last_name = :last_name,
                email = :email,
                username = :username,
                password = :password,
                logout_url = :logout_url
            WHERE account_id = :account_id
        ");

        $db->bindAll(array(
            "first_name" => $info["first_name"],
            "last_name" => $info["last_name"],
            "email" => $info["email"],
            "username" => $info["username"],
            "password" => md5(md5($info["password"])),
            "logout_url" => Core::getRootUrl(),
            "account_id" => 1 // the admin account is always ID 1
        ));

        try {
            $db->execute();
        } catch (PDOException $e) {
            return array(false, $e->getMessage());
        }

        return array(true, "");
    }


    public static function getAccountByUsername($username) {
        $db = Core::$db;
        $db->query("
            SELECT account_id, account_type, account_status, password, temp_reset_password, login_page, theme, swatch,
                   ui_language
            FROM   {PREFIX}accounts
            WHERE  username = :username
        ");
        $db->bind(":username", $username);
        $db->execute();
        return $db->fetch();
    }

    /**
     * Returns all custom account settings for a user account. This merely queries the
     * account_settings table, nothing more; it doesn't trickle up the inheritance
     * chain to figure out the default settings.
     *
     * @param integer $account_id
     * @return array
     */
    public static function getAccountSettings($account_id)
    {
        $db = Core::$db;

        if (empty($account_id)) {
            return array();
        }

        $db->query("
            SELECT setting_name, setting_value
            FROM   {PREFIX}account_settings
            WHERE  account_id = :account_id
        ");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $hash = array();
        foreach ($db->fetchAll() as $row) {
            $hash[$row['setting_name']] = $row["setting_value"];
        }

        extract(Hooks::processHookCalls("main", compact("account_id", "hash"), array("hash")), EXTR_OVERWRITE);

        return $hash;
    }


    /**
     * Updates any number of settings for a particular user account. As with the similar Settings::set()
     * function, it creates the record if it doesn't already exist.
     *
     * @param integer $account_id
     * @param array $settings a hash of setting name => setting value.
     */
    public static function setAccountSettings($account_id, $settings)
    {
        $db = Core::$db;

        extract(Hooks::processHookCalls("start", compact("account_id", "settings"), array("settings")), EXTR_OVERWRITE);

        while (list($setting_name, $setting_value) = each($settings)) {

            // find out if it already exists
            $db->query("
                SELECT count(*) as c
                FROM   {PREFIX}account_settings
                WHERE  setting_name = :setting_name AND
                       account_id = $account_id
            ");
            $db->bindAll(array(
                ":setting_name" => $setting_name,
                ":account_id" => $account_id
            ));
            $db->execute();
            $info = $db->fetch();

            if ($info["c"] == 0) {
                $db->query("
                    INSERT INTO {PREFIX}account_settings (account_id, setting_name, setting_value)
                    VALUES (:account_id, :setting_name, :setting_value)
                ");
            } else {
                $db->query("
                    UPDATE {PREFIX}account_settings
                    SET    setting_value = :setting_value
                    WHERE  setting_name  = :setting_name AND
                           account_id = :account_id
                ");
            }

            $db->bindAll(array(
                ":account_id" => $account_id,
                ":setting_name" => $setting_name,
                ":setting_value" => $setting_value
            ));
            $db->execute();
        }

        extract(Hooks::processHookCalls("end", compact("account_id", "settings"), array()), EXTR_OVERWRITE);
    }


    /**
     * Figure out if an account exists or not.
     */
    public static function accountExists($account_id)
    {
        $db = Core::$db;

        if (empty($account_id) || !is_numeric($account_id)) {
            return false;
        }

        $db->query("
            SELECT count(*) as c
            FROM {PREFIX}accounts
            WHERE account_id = :account_id
        ");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $result = $db->fetch();

        return ($result["c"] == 1);
    }


    /**
     * Retrieves all information about any user account (administrator or client).
     *
     * @param integer $user_id the unique account ID
     * @return array returns a hash of all pertinent data.
     */
    public static function getAccountInfo($account_id)
    {
        $db = Core::$db;

        $db->query("
            SELECT *
            FROM {PREFIX}accounts
            WHERE account_id = :account_id
        ");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $account_info = $db->fetch();

        if (empty($account_info)) {
            return array();
        }

        // also extract any account-specific settings from account_settings
        $db->query("
            SELECT * 
            FROM {PREFIX}account_settings 
            WHERE account_id = :account_id
        ");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $settings = array();
        foreach ($db->fetchAll() as $row) {
            $settings[$row["setting_name"]] = $row["setting_value"];
        }
        $account_info["settings"] = $settings;

        extract(Hooks::processHookCalls("main", compact("account_info"), array("account_info")), EXTR_OVERWRITE);

        return $account_info;
    }


    /**
     * This function is called when updating a client account and the administrator has chosen to prevent them from
     * choosing any password they entered in the last N times (up to 10).
     *
     * The password_history setting in the users' account_settings table always stores the last 10 encrypted passwords,
     * comma-delimited, and ordered newest to oldest. This function just checks that log against an incoming password
     * to check its validity.
     *
     * @param $account_id
     * @param string $password (encrypted)
     * @param integer the number of items to check in the history. e.g. 5 would only check the last 5 passwords.
     */
    public static function passwordInPasswordHistory($account_id, $password, $num_password_history)
    {
        $account_settings = self::getAccountSettings($account_id);
        $last_passwords = (isset($account_settings["password_history"]) && !empty($account_settings["password_history"])) ?
        explode(",", $account_settings["password_history"]) : array();

        $is_found = false;
        for ($i=0; $i<$num_password_history; $i++) {
            if ($password == $last_passwords[$i]) {
                $is_found = true;
                break;
            }
        }
        return $is_found;
    }


    /**
     * Updates the password history queue for a client account. The assumption is that ft_password_in_password_history()
     * has already been called to determine whether or not the password should be added to the list.
     *
     * @param integer $account_id
     * @param string $password
     */
    public static function addPasswordToPasswordHistory($account_id, $password)
    {
        $passwordHistorySize = Core::getPasswordHistorySize();
        $account_settings = self::getAccountSettings($account_id);
        $last_passwords = (isset($account_settings["password_history"]) && !empty($account_settings["password_history"])) ?
            explode(",", $account_settings["password_history"]) : array();
        array_unshift($last_passwords, $password);
        $trimmed_list = array_splice($last_passwords, 0, $passwordHistorySize);
        $new_password_history = implode(",", $trimmed_list);
        self::setAccountSettings($account_id, array("password_history" => $new_password_history));
    }


    /**
     * Helper function to determine if a username is valid or not. Checks to see that it only contains a-Z, 0-9, ., _
     * and @ chars and that it's not already taken.
     *
     * @param string $username a prospective username
     * @param integer $user_id optional paramter used when editing the username for an account
     * @return array [0]: true/false (success / failure)<br />
     *               [1]: message string
     */
    public static function isValidUsername($username, $account_id = "")
    {
        $LANG = Core::$L;
        $db = Core::$db;

        // check the username is alphanumeric
        if (preg_match("/[^\.a-zA-Z0-9_@]/", $username)) {
            return array(false, $LANG["validation_invalid_client_username2"]);
        }

        $clause = (!empty($account_id)) ? "AND account_id != :account_id" : "";

        // now check the username isn't already taken
        $db->query("
            SELECT count(*)
            FROM   {PREFIX}accounts
            WHERE  username = :username
            $clause
        ");
        $db->bind(":username", $username);
        if (!empty($account_id)) {
            $db->bind(":account_id", $account_id);
        }
        $db->execute();
        $info = $db->fetch();

        if ($info[0] > 0) {
            return array(false, $LANG["validation_username_taken"]);
        } else {
            return array(true, "");
        }
    }


    /**
     * Used by the "forget password?" page to have a client's login information sent to them.
     *
     * @param array $info the $_POST containing a "username" key. That value is used to find the user
     *      account information to email them.
     * @return array [0]: true/false (success / failure)
     *               [1]: message string
     */
    public static function ft_send_password($info)
    {
        global $g_root_url, $g_root_dir, $g_table_prefix, $LANG;

        extract(Hooks::processHookCalls("start", compact("info"), array("info")), EXTR_OVERWRITE);

        $success = true;
        $message = $LANG["notify_login_info_emailed"];

        if (!isset($info["username"]) || empty($info["username"]))
        {
            $success = false;
            $message = $LANG["validation_no_username_or_js"];
            return array($success, $message);
        }
        $username = $info["username"];

        $query = mysql_query("
     SELECT *
     FROM   {$g_table_prefix}accounts
     WHERE  username = '$username'
          ");

        // not found
        if (!mysql_num_rows($query))
        {
            $success = false;
            $message = $LANG["validation_account_not_recognized_info"];
            return array($success, $message);
        }

        $account_info = mysql_fetch_assoc($query);
        $email        = $account_info["email"];

        // one final check: confirm the email is defined & valid
        if (empty($email) || !ft_is_valid_email($email))
        {
            $success = false;
            $message = $LANG["validation_email_not_found_or_invalid"];
            return array($success, $message);
        }

        $account_id   = $account_info["account_id"];
        $username     = $account_info["username"];
        $new_password = ft_generate_password();
        $encrypted_password = md5(md5($new_password));

        // update the database with the new password (encrypted). As of 2.1.0 there's a second field to store the
        // temporary generated password, leaving the original password intact. This prevents a situation arising when
        // someone other than the admin / client uses the "Forget Password" feature and invalidates a valid, known password.
        // Any time the user successfully logs in,
        mysql_query("
    UPDATE {$g_table_prefix}accounts
    SET    temp_reset_password = '$encrypted_password'
    WHERE  account_id = $account_id
      ");

        // now build and sent the email

        // 1. build the email content
        $placeholders = array(
        "login_url" => "$g_root_url/?id=$account_id",
        "email"     => $email,
        "username"  => $username,
        "new_password" => $new_password
        );
        $smarty_template_email_content = file_get_contents("$g_root_dir/global/emails/forget_password.tpl");
        $email_content = General::evalSmartyString($smarty_template_email_content, $placeholders);

        // 2. build the email subject line
        $placeholders = array(
        "program_name" => Settings::get("program_name")
        );
        $smarty_template_email_subject = file_get_contents("$g_root_dir/global/emails/forget_password_subject.tpl");
        $email_subject = trim(General::evalSmartyString($smarty_template_email_subject, $placeholders));

        // if Swift Mailer is enabled, send the emails with that. In case there's a problem sending the message with
        // Swift, it falls back the default mail() function.
        $swift_mail_error = false;
        $swift_mail_enabled = Modules::checkModuleEnabled("swift_mailer");
        if ($swift_mail_enabled)
        {
            $sm_settings = Modules::getModuleSettings("", "swift_mailer");
            if ($sm_settings["swiftmailer_enabled"] == "yes")
            {
                Modules::includeModule("swift_mailer");

                // get the admin info. We'll use that info for the "from" and "reply-to" values. Note
                // that we DON'T use that info for the regular mail() function. This is because retrieving
                // the password is important functionality and we don't want to cause problems that could
                // prevent the email being sent. Many servers don't all the 4th headers parameter of the mail()
                // function
                $admin_info = Administrator::getAdminInfo();
                $admin_email = $admin_info["email"];

                $email_info  = array();
                $email_info["to"]  = array();
                $email_info["to"][] = array("email" => $email);
                $email_info["from"] = array();
                $email_info["from"]["email"] = $admin_email;
                $email_info["subject"] = $email_subject;
                $email_info["text_content"] = $email_content;
                list($success, $sm_message) = swift_send_email($email_info);

                // if the email couldn't be sent, display the appropriate error message. Otherwise
                // the default success message is used
                if (!$success)
                {
                    $swift_mail_error = true;
                    $message = $sm_message;
                }
            }
        }

        // if there was an error sending with Swift, or if it wasn't installed, send it by mail()
        if (!$swift_mail_enabled || $swift_mail_error)
        {
            // send email [note: the double quotes around the email recipient and content are intentional: some systems fail without it]
            if (!@mail("$email", $email_subject, $email_content))
            {
                $success = false;
                $message = $LANG["notify_email_not_sent"];
                return array($success, $message);
            }
        }

        extract(Hooks::processHookCalls("end", compact("success", "message", "info"), array("success", "message")), EXTR_OVERWRITE);

        return array($success, $message);
    }
}

