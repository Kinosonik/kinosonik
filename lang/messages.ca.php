<?php
// Català
return [
  'error' => [
    // Login / autenticació
    'invalid_credentials' => "El correu electrònic o la contrasenya no són correctes.",
    'email_not_found'     => "No existeix cap usuari amb aquest correu electrònic.",
    'wrong_password'      => "La contrasenya no és correcta.",
    'login_required'      => "Has d'iniciar sessió per accedir a aquesta secció.",
    'email_not_verified'  => "Has de verificar el teu correu abans d'iniciar sessió.",
    'too_many_attempts'   => "Massa intents. Torna més tard.",

    // Registre
    'missing_fields'      => "Falten camps obligatoris al formulari.",
    'password_mismatch'   => "Les contrasenyes no coincideixen.",
    'weak_password'       => "La contrasenya ha de tenir almenys 8 caràcters i incloure lletres i números.",
    'invalid_phone'       => "El telèfon no és vàlid. Usa el format internacional, p. ex. +34656765467.",
    'policy_required'     => "Has d'acceptar la política de privacitat per poder registrar-te.",
    'email_in_use'        => "Ja existeix un compte amb aquest correu electrònic.",

    // Recuperació / verificació
    'token_invalid'       => "L'enllaç no és vàlid o ja s'ha utilitzat.",
    'token_expired'       => "L’enllaç ha caducat.",
    'reset_failed'        => "No s'ha pogut restablir la contrasenya. Torna-ho a intentar.",
    'verify_failed'       => "No s'ha pogut verificar el teu correu.",

    // Permisos / estat
    'forbidden'           => "No tens permisos per accedir a aquesta secció.",
    'not_implemented'     => "Funcionalitat encara no implementada.",
    'self_delete'         => "No pots eliminar el teu propi usuari.",

    // Genèrics
    'db_error'            => "Error intern de base de dades. Torna-ho a provar més tard.",
    'server_error'        => "Error intern del servidor. Disculpa les molèsties.",
    'invalid_request'     => "Petició no vàlida.",
    'bad_method'          => "Mètode de petició no vàlid.",
    'csrf'                => "Sessió expirada o petició no vàlida. Torna-ho a intentar.",
    'default'             => "S'ha produït un error inesperat.",

    // Upload riders
    'file_missing'        => "No s'ha trobat cap fitxer adjunt.",
    'upload_error'        => "Error en pujar el fitxer.",
    'file_too_large'      => "El fitxer supera el límit permès (20 MB).",
    'invalid_filetype'    => "Només s'accepten fitxers PDF.",
    'quota_exceeded'      => "Has superat la quota d’emmagatzematge (500 MB). Elimina algun rider o contacta amb suport.",
    'upload_failed'       => "No s'ha pogut pujar el fitxer al magatzem.",
    'rider_bad_link'      => "Hi ha algun problema amb l’enllaç del rider.",
    'rider_not_found'     => "Aquest rider ja no està disponible (pot haver estat eliminat).",
    'rider_no_access'     => "Aquest rider encara no té segell i no és accessible públicament.",
    'rider_storage_error' => "No hem pogut recuperar el PDF del rider. Torna-ho a provar més tard.",
    'rider_meta_error'    => "No s’ha pogut obtenir la informació de verificació.",

    // Validacions (crear/editar)
    'missing_description' => "Falta la descripció del rider.",
    'no_file'             => "No s’ha seleccionat cap fitxer.",
    'file_size'           => "El fitxer és massa gran o està buit.",
    'file_type'           => "Només es permeten fitxers PDF.",
    'hash_error'          => "No s’ha pogut calcular el hash SHA-256 del fitxer.",
    'r2_upload'           => "Error en pujar el fitxer a l’emmagatzematge.",
    'db_insert'           => "Error en desar el rider a la base de dades.",

    // Errors específics d’UPLOAD_ERR
    'upload_err_ini_size'   => 'El fitxer supera el límit màxim configurat al servidor.',
    'upload_err_form_size'  => 'El fitxer supera el límit màxim permès pel formulari.',
    'upload_err_partial'    => 'El fitxer només s’ha pujat parcialment.',
    'upload_err_no_file'    => 'No s’ha seleccionat cap fitxer.',
    'upload_err_no_tmp_dir' => 'Falta el directori temporal al servidor.',
    'upload_err_cant_write' => 'No s’ha pogut escriure el fitxer al disc.',
    'upload_err_extension'  => 'Una extensió del servidor ha aturat la pujada.',
    'upload_err_unknown'    => 'Error desconegut en la pujada del fitxer.',

    'ai_locked_state' => 'Aquest rider ja és definitiu (validat o caducat).',

    'confirm_required' => 'Cal confirmar l’eliminació escrivint la paraula indicada.',
    
  ],

  'success' => [
    'default'         => "Operació realitzada correctament.",
    'registered'      => "Usuari registrat correctament. Ja pots accedir a la teva àrea privada.",
    'login_ok'        => "Has iniciat sessió correctament.",
    'updated'         => "Les teves dades s'han actualitzat correctament.",
    'deleted'         => "El teu compte s'ha eliminat de manera permanent.",
    'user_deleted'    => "Usuari eliminat correctament.",
    'account_deleted' => "El teu compte i els riders associats s’han eliminat correctament.",

    'verify_sent'     => "T'hem enviat un correu per verificar el teu compte. Revisa la bústia.",
    'verify_ok'       => "Verificació completada! Ja pots iniciar sessió.",
    'verify_resent'   => "T'hem reenviat el correu de verificació.",

    'mail_sent'       => "Si l’adreça existeix, t’hem enviat un correu amb instruccions.",
    'reset_ok'        => "Contrasenya restablerta correctament. Ja pots iniciar sessió.",

    'rider_uploaded'  => "Rider pujat correctament.",
    'uploaded'        => "Rider pujat correctament.",
    'rider_deleted'   => "Rider eliminat correctament.",
    'ai_scored'       => "Felicitats: valoració amb IA actualitzada.",
    'expired_ok' => 'Rider caducat correctament.',
    'seal_expired' => 'Rider caducat correctament.',

    'ai_scored' => 'Anàlisi feta. Puntuació: {score}/100',

    'role_tecnic_on'   => 'Rol de tècnic activat correctament. Ja pots pujar i gestionar riders.',
    'role_tecnic_off'  => 'Rol de tècnic desactivat i riders eliminats correctament.',
  ],
];