<?php
/**
 * MU-Plugin : redirige wp_mail() vers Mailpit en local.
 * Activé si la constante MAILPIT_HOST est définie (via WORDPRESS_CONFIG_EXTRA).
 * Ne fait rien en production (constante absente).
 */
if (!defined('MAILPIT_HOST')) return;

add_action('phpmailer_init', function (PHPMailer\PHPMailer\PHPMailer $phpmailer): void {
    $phpmailer->isSMTP();
    $phpmailer->Host       = MAILPIT_HOST;
    $phpmailer->Port       = defined('MAILPIT_PORT') ? (int) MAILPIT_PORT : 1025;
    $phpmailer->SMTPAuth   = false;
    $phpmailer->SMTPSecure = '';
    $phpmailer->From       = defined('MAILPIT_FROM') ? MAILPIT_FROM : 'wordpress@societies.local';
    $phpmailer->FromName   = 'Societies (local)';
}, 1); // Priorité 1 — avant tout autre plugin
