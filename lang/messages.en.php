<?php
// English
return [
  'error' => [
    // Login / authentication
    'invalid_credentials' => "The email or password is incorrect.",
    'email_not_found'     => "No user found with that email address.",
    'wrong_password'      => "The password is incorrect.",
    'login_required'      => "You must log in to access this section.",
    'email_not_verified'  => "You must verify your email before logging in.",
    'too_many_attempts'   => "Too many attempts. Please try again later.",

    // Registration
    'missing_fields'      => "Required fields are missing from the form.",
    'password_mismatch'   => "Passwords do not match.",
    'weak_password'       => "Password must be at least 8 characters and include both letters and numbers.",
    'invalid_phone'       => "Invalid phone number. Use international format, e.g. +34656765467.",
    'policy_required'     => "You must accept the privacy policy to register.",
    'email_in_use'        => "An account with that email address already exists.",

    // Recovery / verification
    'token_invalid'       => "The link is invalid or has already been used.",
    'token_expired'       => "The link has expired.",
    'reset_failed'        => "Password reset failed. Please try again.",
    'verify_failed'       => "Your email could not be verified.",

    // Permissions / state
    'forbidden'           => "You do not have permission to access this section.",
    'not_implemented'     => "Functionality not yet implemented.",
    'self_delete'         => "You cannot delete your own user.",

    // Generics
    'db_error'            => "Internal database error. Please try again later.",
    'server_error'        => "Internal server error. Sorry for the inconvenience.",
    'invalid_request'     => "Invalid request.",
    'bad_method'          => "Invalid request method.",
    'csrf'                => "Session expired or invalid request. Please try again.",
    'default'             => "An unexpected error occurred.",

    // Upload riders
    'file_missing'        => "No file was attached.",
    'upload_error'        => "Error uploading the file.",
    'file_too_large'      => "The file exceeds the allowed limit (20 MB).",
    'invalid_filetype'    => "Only PDF files are accepted.",
    'quota_exceeded'      => "You have exceeded the storage quota (500 MB). Delete a rider or contact support.",
    'upload_failed'       => "The file could not be uploaded to storage.",
    'rider_bad_link'      => "There is a problem with the rider link.",
    'rider_not_found'     => "This rider is no longer available (it may have been deleted).",
    'rider_no_access'     => "This rider does not yet have a seal and is not publicly accessible.",
    'rider_storage_error' => "We could not retrieve the rider PDF. Please try again later.",
    'rider_meta_error'    => "The verification information could not be retrieved.",

    // Validations (create/edit)
    'missing_description' => "Rider description is missing.",
    'no_file'             => "No file selected.",
    'file_size'           => "The file is too large or empty.",
    'file_type'           => "Only PDF files are allowed.",
    'hash_error'          => "Could not calculate SHA-256 hash of the file.",
    'r2_upload'           => "Error uploading the file to storage.",
    'db_insert'           => "Error saving the rider to the database.",

    // Specific UPLOAD_ERR messages
    'upload_err_ini_size'   => 'The file exceeds the maximum limit configured on the server.',
    'upload_err_form_size'  => 'The file exceeds the maximum limit allowed by the form.',
    'upload_err_partial'    => 'The file was only partially uploaded.',
    'upload_err_no_file'    => 'No file was selected.',
    'upload_err_no_tmp_dir' => 'The temporary directory is missing on the server.',
    'upload_err_cant_write' => 'The file could not be written to disk.',
    'upload_err_extension'  => 'A server extension stopped the upload.',
    'upload_err_unknown'    => 'Unknown error during file upload.',

    'ai_locked_state' => 'This rider is final (validated or expired).',
    'confirm_required' => 'You must confirm the deletion by typing the indicated word.',
  ],

  'success' => [
    'default'         => "Operation completed successfully.",
    'registered'      => "User registered successfully. You can now access your private area.",
    'login_ok'        => "You have logged in successfully.",
    'updated'         => "Your details have been updated successfully.",
    'deleted'         => "Your account has been permanently deleted.",
    'user_deleted'    => "User deleted successfully.",
    'account_deleted' => "Your account and associated riders have been deleted successfully.",

    'verify_sent'     => "We have sent you an email to verify your account. Check your inbox.",
    'verify_ok'       => "Verification completed! You can now log in.",
    'verify_resent'   => "We have resent the verification email.",

    'mail_sent'       => "If the address exists, we have sent you an email with instructions.",
    'reset_ok'        => "Password successfully reset. You can now log in.",

    'rider_uploaded'  => "Rider uploaded successfully.",
    'uploaded'        => "Rider uploaded successfully.",
    'rider_deleted'   => "Rider deleted successfully.",
    'ai_scored'       => "Congratulations: AI score updated.",
    'expired_ok' => 'Rider expired successfully.',

    'ai_scored' => 'Analysis done. Score: {score}/100',

    'role_tecnic_on'   => 'Technician role enabled successfully. You can now upload and manage riders.',
    'role_tecnic_off'  => 'Technician role disabled and riders deleted successfully.',
  ],
];