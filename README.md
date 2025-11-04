<a href='https://ko-fi.com/W7W61NW50T' target='_blank'><img height='36' style='border:0px;height:36px;' src='https://storage.ko-fi.com/cdn/kofi6.png?v=6' border='0' alt='Buy Me a Coffee at ko-fi.com' /></a>

# MailCow Automated Provisioning Module for WHMCS 

## [Install and Configure Wiki](https://github.com/lucianlazar1983/whmcs-mailcow/wiki)
## Description

This is an advanced provisioning module designed to integrate WHMCS with MailCow email servers. Its primary goal is to fully automate the lifecycle of an email service, from domain creation to domain admin management, directly from the WHMCS admin panel.

The module utilizes a combination of **Configurable Options** (for package flexibility) and the service's **Standard Fields** (`Username`/`Password`), providing a clean and fully integrated administration experience.

## Key Features

The module manages the entire lifecycle of an email service, including both the domain and its associated domain administrator account.

* **Automatic Creation (The `Create` Button)**
    * Creates a new domain in MailCow using the limits defined in the "Configurable Options".
    * Automatically sets a fixed default mailbox quota of **1024 MB** (this value is not overridden on subsequent upgrades).
    * Automatically generates a unique **Domain Administrator** (format: `admin-xxxxx-ClientID`) and a 22-character strong password.
    * Automatically saves these credentials into the standard `Username` and `Password` fields of the WHMCS service, as well as into an internal custom field.

* **Suspension (The `Suspend` Button)**
    * Deactivates both the main domain and the associated domain administrator account in MailCow.

* **Unsuspension (The `Unsuspend` Button)**
    * Reactivates both the domain and the associated domain administrator account in MailCow.

* **Termination (The `Terminate` Button)**
    * Performs a complete and safe cleanup:
        1.  Deletes the domain administrator account from MailCow.
        2.  Deletes all mailboxes associated with the domain.
        3.  Deletes the main domain.
        4.  Clears the `Username`, `Password`, and internal custom field data from WHMCS.

* **Package Modification (The `Change Package` Button)**
    * Updates the limits (Total Quota, Max Mailboxes, Max Aliases) on the MailCow server based on the configurable options selected in WHMCS.
    * **Important:** This function **does not** modify the default mailbox quota (`defquota`), allowing users to customize this value from their MailCow panel without it being overwritten by WHMCS.

* **Password Change (The `Change Password` Button)**
    * Uses the standard WHMCS functionality. After changing the password in the `Password` field and clicking "Save Changes", the "Change Password" button is used to push the new password to MailCow for the domain administrator.

* **Username Change (The `Change Username` Button)**
    * Allows the WHMCS administrator to change the domain administrator's username.
    * This requires saving the new username in the standard `Username` field, followed by clicking the "Change Username" button to synchronize the change with MailCow.

## Technical Logic and Requirements

To function correctly, the module relies on a specific configuration within WHMCS:

1.  **Configurable Options (Required):** The module reads package limits from "Configurable Options". It is **critical** that their "Option Name" (the one used by the system) is **exactly** as follows, regardless of your WHMCS language:
    * `Quota Totale Dominio`
    * `Max Caselle`
    * `Max Alias`

2.  **Standard Fields (`Username`/`Password`):** The module uses the standard WHMCS service fields to store and manage the generated domain administrator credentials.

3.  **Internal Custom Field (Required):** One custom field **must** be created for the module's internal operation:
    * **Field Name:** `mailcow_admin_username`
    * **Type:** `Text Box`
    * **Setting:** Admin Only
    * **Warning:** This field is used to sync username changes and **must not be modified manually**.

## Known Limitations

* The module does not manage the creation/deletion of individual mailboxes (this is handled by the created domain administrator).
* Correct operation depends on the exact matching of the Configurable Option names and the internal Custom Field name.


## Please refer to the [Wiki Section](https://github.com/lucianlazar1983/whmcs-mailcow/wiki) for complete installation and usage.
