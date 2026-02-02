#!/bin/bash
#===============================================================================
# dirperm.sh – Directory and file permission normaliser
#
# This script ensures consistent ownership and permissions across the PHP‑MC
# project tree.  It is safe to run from cron (hourly) or manually by an admin.
#
# Author: CodeWalker lmstudio/openai/gpt-oss-20b
# Date:   2025-09-28
#===============================================================================

set -euo pipefail


# if not root, exit
if [[ $EUID -ne 0 ]]; then
   echo "This script must be run as root"
   exit 1
fi

# Backup last 200 lines of webserver error log
# Detects if apache or nginx is used
mkdir -p /web/private/logs
if [ -f /var/log/apache2/error.log ]; then
    tail -n 200 /var/log/apache2/error.log > /web/private/logs/error.log
elif [ -f /var/log/httpd/error_log ]; then
    tail -n 200 /var/log/httpd/error_log > /web/private/logs/error.log
elif [ -f /var/log/nginx/error.log ]; then
    tail -n 200 /var/log/nginx/error.log > /web/private/logs/error.log
else
    echo "No web server error log found."
    #exit 1
fi

# --------------------------------------------------------------------------- #
# Configuration
# --------------------------------------------------------------------------- #


# Base directory of the project
PROJECT_ROOT="/web"
PRIVATE_DIR="$PROJECT_ROOT/private"
PUBLIC_DIR="$PROJECT_ROOT/html"

# User and group that should own all files
OWNER_USER="samekhi"
OWNER_GROUP="www-data"
OLLAMA="ollama"


# Permissions to apply
# NOTE: 2xxx is setgid (NOT sticky). Sticky is 1xxx.
WRITEABLE_DIR_PERM=2770   # setgid + rwx for owner/group
WRITEABLE_FILE_PERM=660   # rw for owner/group

EXEC_DIR_PERM=2750        # setgid + rwx for owner, rx for group
EXEC_FILE_PERM=750        # rwx for owner, rx for group

HTACCESS_PERM=640         # rw for owner, r for group

PUBLIC_DIR_PERM=755       # rwx for owner, rx for group/others
PUBLIC_FILE_PERM=644      # rw for owner, r for group/others

# --------------------------------------------------------------------------- #
# Directories that should be writable 
# --------------------------------------------------------------------------- #
WRITEABLE_DIRS=(
    "$PROJECT_ROOT"
    "$PRIVATE_DIR"
    "$PRIVATE_DIR/uploads"
    "$PRIVATE_DIR/logs"    
    "$PRIVATE_DIR/locks"    
    "$PRIVATE_DIR/ratelimit"
    "$PRIVATE_DIR/cache"
    "$PRIVATE_DIR/storage"    
    "$PRIVATE_DIR/uploads/memory"
    "$PRIVATE_DIR/admin_modules"       
    "$PRIVATE_DIR/db"
    "$PRIVATE_DIR/db/inbox"
    "$PRIVATE_DIR/db/incoming"    
    "$PRIVATE_DIR/db/memory"


)
# --------------------------------------------------------------------------- #
# Directories that should be executable 
# --------------------------------------------------------------------------- #
EXECUTABLE_DIRS=(    
    "$PROJECT_ROOT/private/scripts"
    "$PROJECT_ROOT/private/bin"
    "$PROJECT_ROOT/.githooks"
)
# --------------------------------------------------------------------------- #
# Directories that should be Public 644
# --------------------------------------------------------------------------- #
PUBLIC_DIRS=(
    "$PUBLIC_DIR"
)
# --------------------------------------------------------------------------- #
# Excluded directories
# example: AI models dir
# --------------------------------------------------------------------------- #
EXCLUDED_DIRS=(
    "$PROJECT_ROOT/ai/ollama"
)

# --------------------------------------------------------------------------- #
for dir in "${WRITEABLE_DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        chown -c "${OWNER_USER}:${OWNER_GROUP}" "$dir"
        chmod "${WRITEABLE_DIR_PERM}" "$dir"
    fi
done


for dir in "${EXECUTABLE_DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        chown -c "${OWNER_USER}:${OWNER_GROUP}" "$dir"
        chmod "${EXEC_DIR_PERM}" "$dir"
    fi
done


for dir in "${PUBLIC_DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        chown -c "${OWNER_USER}:${OWNER_GROUP}" "$dir"
        chmod "${PUBLIC_DIR_PERM}" "$dir"
    fi
done

# --------------------------------------------------------------------------- #
# Core logic
# --------------------------------------------------------------------------- #
main() {
    # 1. Ensure project ownership
    chown -R -c "${OWNER_USER}:${OWNER_GROUP}" "$PROJECT_ROOT"

    # 2. Set HTACCESS_PERM for .htaccess files
    find "$PROJECT_ROOT" -type f -name ".htaccess" -exec chmod "${HTACCESS_PERM}" {} +
    find "$PUBLIC_DIR" -type f -name ".htaccess" -exec chmod "${HTACCESS_PERM}" {} +


    # 3. Set directory permissions for writeable dirs
    for dir in "${WRITEABLE_DIRS[@]}"; do
        chown -R -c "${OWNER_USER}:${OWNER_GROUP}" "$dir"         
        chmod -R "${WRITEABLE_DIR_PERM}" "$dir" 
        # set all files in these dirs to be writeable
        find "$dir" -type f -exec chmod "${WRITEABLE_FILE_PERM}" {} +
    done

    # 4. Ensure files are executable    
    for dir in "${EXECUTABLE_DIRS[@]}"; do 
        chown -R -c "${OWNER_USER}:${OWNER_GROUP}" "$dir"
        chmod -R "${EXEC_DIR_PERM}" "$dir"
        find "$dir" -type f -exec chmod "${EXEC_FILE_PERM}" {} +
       
    done

    # 5. Ensure files are Public 644    
    for dir in "${PUBLIC_DIRS[@]}"; do 
        #            
        chown -R -c "${OWNER_USER}:${OWNER_GROUP}" "$dir"
        # set public dir 755 permissions
        chmod -R "${PUBLIC_DIR_PERM}" "$dir"
        # Set all files in these dirs to be 644
        find "$dir" -type f -exec chmod "${PUBLIC_FILE_PERM}" {} +

    done

    # 6. OLLAMA models dir exclusion
    for dir in "${EXCLUDED_DIRS[@]}"; do
        if [ -d "$dir" ]; then
            if getent group "$OLLAMA" >/dev/null 2>&1; then
                chown -R -c "${OWNER_USER}:${OLLAMA}" "$dir"
                chmod -R 770 "$dir"
            else
                echo "Skipping '$dir' (group '$OLLAMA' not found)."
            fi
        fi
    done

    # 7. Git sentinels (keep dirs tracked, ignore contents)
    printf '*\n!.gitignore\n' > "$PROJECT_ROOT/private/db/.gitignore"
    printf '*.log\n*.log.*\n!.gitignore\n!README.md\n' > "$PROJECT_ROOT/private/logs/.gitignore"    

    echo "Permission normalisation complete."
}

# --------------------------------------------------------------------------- #
# Entry point
# --------------------------------------------------------------------------- #

main "$@"
