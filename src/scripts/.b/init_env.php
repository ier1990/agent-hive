<?php
// Initialize default .env if missing

$envFile = '/web/private/.env';

if (file_exists($envFile)) {
    echo "✓ .env already exists at $envFile\n";
    exit(0);
}

// Ensure directory structure exists
$envDir = dirname($envFile);
if (!is_dir($envDir)) {
    if (!@mkdir($envDir, 0755, true)) {
        echo "✗ Failed to create directory: $envDir\n";
        exit(1);
    }
    echo "✓ Created directory: $envDir\n";
}

$defaults = <<<'ENV'
# Security Mode: lan (trust RFC1918 IPs) or public (require keys for all)
SECURITY_MODE=lan

# IPs that can access without API key (comma-separated CIDR)
# Only used in lan mode
ALLOW_IPS_WITHOUT_KEY=127.0.0.1/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16

# Require API key even for allowed IPs (0=no, 1=yes)
REQUIRE_KEY_FOR_ALL=0

# LLM Backend (set via admin UI or manually)
LLM_BASE_URL=http://127.0.0.1:1234
LLM_API_KEY=
APP_API_KEY=

# Service Identity
APP_VERSION=1.0.0
APP_SERVICE_NAME=iernc-api

ENV;

if (file_put_contents($envFile, $defaults) === false) {
    echo "✗ Failed to create $envFile\n";
    exit(1);
}

chmod($envFile, 0640); // rw-r-----

echo "✓ Created default .env at $envFile\n";
echo "  Edit this file to configure your installation.\n";
exit(0);