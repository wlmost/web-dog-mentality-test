<?php
declare(strict_types=1);

require_once __DIR__ . '/WizardHelper.php';

// Gesperrt?
if (WizardHelper::isLocked()) {
    http_response_code(403);
    echo '<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Wizard gesperrt</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f5f5;padding:2rem;}
.box{max-width:500px;margin:0 auto;background:#fff;border-radius:8px;padding:2rem;box-shadow:0 2px 8px rgba(0,0,0,.1);}
h1{font-size:1.3rem;color:#1a1a2e;margin-bottom:1rem;}
.warn{background:#fef9e7;border-left:4px solid #f39c12;padding:.75rem 1rem;border-radius:4px;}
code{background:#f0f0f0;padding:.1rem .3rem;border-radius:3px;}
</style></head>
<body><div class="box">
<h1>&#x1F512; Wizard gesperrt</h1>
<div class="warn">Der Installations-Wizard wurde nach erfolgreicher Installation gesperrt.<br><br>
Bitte entfernen Sie das Verzeichnis <code>/wizard/</code> <strong>sofort</strong> per FTP von Ihrem Server.</div>
</div></body></html>';
    exit();
}

// Routing
if (WizardHelper::isConfigured()) {
    // config.local.php vorhanden → Update-Wizard
    header('Location: update.php');
} else {
    // Neuinstallation
    header('Location: install.php');
}
exit();
