#!/bin/bash
# Root Guardian - Virtual Environment Setup & Activation
# Usage: source ./activate.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV_DIR="$SCRIPT_DIR/.venv"

# Create venv if it doesn't exist
if [ ! -d "$VENV_DIR" ]; then
    echo "Creating virtual environment..."
    python3 -m venv "$VENV_DIR"
    echo "Virtual environment created."
fi

# Activate the venv
source "$VENV_DIR/bin/activate"

# Install/upgrade dependencies if requirements.txt exists
if [ -f "$SCRIPT_DIR/requirements.txt" ]; then
    echo "Installing dependencies from requirements.txt..."
    pip install -q --upgrade pip setuptools wheel
    pip install -q -r "$SCRIPT_DIR/requirements.txt"
    echo "Dependencies installed. Ready to run Root Guardian!"
fi

# Show help
echo ""
echo "Root Guardian - Interactive AI Diagnostics"
echo "=========================================="
echo ""
echo "Quick start:"
echo "  python3 agent_bash.py"
echo ""
echo "Type /help once in the shell for all available commands."
echo "Virtual environment can be activated with command like:"
echo ""
echo "source $VENV_DIR/bin/activate"

