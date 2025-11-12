<?php
// Castellano
return [
  'error' => [
    // Login / autenticación
    'invalid_credentials' => "El correo electrónico o la contraseña no son correctos.",
    'email_not_found'     => "No existe ningún usuario con ese correo electrónico.",
    'wrong_password'      => "La contraseña no es correcta.",
    'login_required'      => "Debes iniciar sesión para acceder a esta sección.",
    'email_not_verified'  => "Debes verificar tu correo antes de iniciar sesión.",
    'too_many_attempts'   => "Demasiados intentos. Vuelve más tarde.",

    // Registro
    'missing_fields'      => "Faltan campos obligatorios en el formulario.",
    'password_mismatch'   => "Las contraseñas no coinciden.",
    'weak_password'       => "La contraseña debe tener al menos 8 caracteres e incluir letras y números.",
    'invalid_phone'       => "El teléfono no es válido. Usa el formato internacional, p. ej. +34656765467.",
    'policy_required'     => "Debes aceptar la política de privacidad para poder registrarte.",
    'email_in_use'        => "Ya existe una cuenta con ese correo electrónico.",
    'token_expired' => 'El enlace ha caducado. Solicita uno nuevo desde tu perfil.',
    'verify_failed' => 'Error del sistema. Contacta con soporte si el problema persiste.',
    'verify_rate_limit' => 'Demasiados intentos de verificación. Espera unos minutos.',


    // Recuperación / verificación
    'token_invalid'       => "El enlace no es válido o ya se ha utilizado.",
    'token_expired'       => "El enlace ha caducado.",
    'reset_failed'        => "No se ha podido restablecer la contraseña. Inténtalo de nuevo.",
    'verify_failed'       => "No se ha podido verificar tu correo.",

    // Permisos / estado
    'forbidden'           => "No tienes permisos para acceder a esta sección.",
    'not_implemented'     => "Funcionalidad aún no implementada.",
    'self_delete'         => "No puedes eliminar tu propio usuario.",

    // Genéricos
    'db_error'            => "Error interno de base de datos. Inténtalo más tarde.",
    'server_error'        => "Error interno del servidor. Disculpa las molestias.",
    'invalid_request'     => "Petición no válida.",
    'bad_method'          => "Método de petición no válido.",
    'csrf'                => "Sesión expirada o petición no válida. Inténtalo de nuevo.",
    'default'             => "Se ha producido un error inesperado.",

    // Upload riders
    'file_missing'        => "No se ha encontrado ningún archivo adjunto.",
    'upload_error'        => "Error al subir el archivo.",
    'file_too_large'      => "El archivo supera el límite permitido (20 MB).",
    'invalid_filetype'    => "Solo se aceptan archivos PDF.",
    'quota_exceeded'      => "Has superado la cuota de almacenamiento (500 MB). Elimina algún rider o contacta con soporte.",
    'upload_failed'       => "No se ha podido subir el archivo al almacenamiento.",
    'rider_bad_link'      => "Hay un problema con el enlace del rider.",
    'rider_not_found'     => "Este rider ya no está disponible (puede haber sido eliminado).",
    'rider_no_access'     => "Este rider todavía no tiene sello y no es accesible públicamente.",
    'rider_storage_error' => "No hemos podido recuperar el PDF del rider. Inténtalo de nuevo más tarde.",
    'rider_meta_error'    => "No se ha podido obtener la información de verificación.",

    // Validaciones (crear/editar)
    'missing_description' => "Falta la descripción del rider.",
    'no_file'             => "No se ha seleccionado ningún archivo.",
    'file_size'           => "El archivo es demasiado grande o está vacío.",
    'file_type'           => "Solo se permiten archivos PDF.",
    'hash_error'          => "No se ha podido calcular el hash SHA-256 del archivo.",
    'r2_upload'           => "Error al subir el archivo al almacenamiento.",
    'db_insert'           => "Error al guardar el rider en la base de datos.",

    // Errores específicos de UPLOAD_ERR
    'upload_err_ini_size'   => 'El archivo supera el límite máximo configurado en el servidor.',
    'upload_err_form_size'  => 'El archivo supera el límite máximo permitido por el formulario.',
    'upload_err_partial'    => 'El archivo solo se ha subido parcialmente.',
    'upload_err_no_file'    => 'No se ha seleccionado ningún archivo.',
    'upload_err_no_tmp_dir' => 'Falta el directorio temporal en el servidor.',
    'upload_err_cant_write' => 'No se ha podido escribir el archivo en el disco.',
    'upload_err_extension'  => 'Una extensión del servidor ha detenido la subida.',
    'upload_err_unknown'    => 'Error desconocido en la subida del archivo.',

    'ai_locked_state' => 'Este rider ya es definitivo (validado o caducado).',

    'confirm_required' => 'Es necesario confirmar la eliminación escribiendo la palabra indicada.',

  ],

  'success' => [
    'default'         => "Operación realizada correctamente.",
    'registered'      => "Usuario registrado correctamente. Ya puedes acceder a tu área privada.",
    'login_ok'        => "Has iniciado sesión correctamente.",
    'updated'         => "Tus datos se han actualizado correctamente.",
    'deleted'         => "Tu cuenta se ha eliminado de forma permanente.",
    'user_deleted'    => "Usuario eliminado correctamente.",
    'account_deleted' => "Tu cuenta y los riders asociados se han eliminado correctamente.",

    'verify_sent'     => "Te hemos enviado un correo para verificar tu cuenta. Revisa la bandeja de entrada.",
    'verify_ok'       => "¡Verificación completada! Ya puedes iniciar sesión.",
    'verify_resent'   => "Te hemos reenviado el correo de verificación.",

    'mail_sent'       => "Si la dirección existe, te hemos enviado un correo con instrucciones.",
    'reset_ok'        => "Contraseña restablecida correctamente. Ya puedes iniciar sesión.",

    'rider_uploaded'  => "Rider subido correctamente.",
    'uploaded'        => "Rider subido correctamente.",
    'rider_deleted'   => "Rider eliminado correctamente.",
    'ai_scored'       => "Enhorabuena: valoración con IA actualizada.",
    'expired_ok' => 'Rider caducado correctamente.',

    'ai_scored' => 'Análisis hecho. Puntuación: {score}/100',

    'role_tecnic_on'   => 'Rol de técnico activado correctamente. Ya puedes subir y gestionar riders.',
    'role_tecnic_off'  => 'Rol de técnico desactivado y riders eliminados correctamente.',
    'verify_ok' => 'Correo verificado correctamente. Bienvenido/a a Kinosonik Riders.',
    'email_verified' => 'Cuenta activada con éxito. Ya puedes usar todas las funcionalidades.',
  ],
];