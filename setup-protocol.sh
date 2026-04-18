#!/bin/bash
# ============================================
# Adversarial Minds Protocol — Project Setup
# ============================================
# Run this in any project root to set up the protocol.
# Usage: bash /path/to/setup-protocol.sh
# ============================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(pwd)"

echo ""
echo "=== Adversarial Minds Protocol Setup ==="
echo "Project: $PROJECT_DIR"
echo ""

# Check if we're in a git repo
if [ ! -d ".git" ]; then
    echo "[!] Warning: Not a git repository. Some features (hooks, worktrees) need git."
    echo "    Run 'git init' first if you want full functionality."
    echo ""
fi

# Create folder structure
echo "[1/7] Creating folder structure..."
mkdir -p .claude/prompts
mkdir -p .claude/rules
mkdir -p .claude/commands
mkdir -p specs
echo "  Created: .claude/prompts/ .claude/rules/ .claude/commands/ specs/"

# Copy prompt files
echo ""
echo "[2/7] Copying phase prompts..."
for file in interview architect attack write-tests build review deploy; do
    if [ -f "$SCRIPT_DIR/.claude/prompts/$file.md" ]; then
        cp "$SCRIPT_DIR/.claude/prompts/$file.md" ".claude/prompts/$file.md"
        echo "  Copied: .claude/prompts/$file.md"
    else
        echo "  [!] Missing: $SCRIPT_DIR/.claude/prompts/$file.md"
    fi
done

# Copy command files (slash commands)
echo ""
echo "[3/7] Copying slash commands..."
for file in interview architect attack write-tests build review deploy learn hotfix quick micro setup start feedback; do
    if [ -f "$SCRIPT_DIR/.claude/commands/$file.md" ]; then
        cp "$SCRIPT_DIR/.claude/commands/$file.md" ".claude/commands/$file.md"
        echo "  Copied: .claude/commands/$file.md"
    else
        echo "  [!] Missing: $SCRIPT_DIR/.claude/commands/$file.md"
    fi
done

# Copy rule files
echo ""
echo "[4/7] Copying rule templates..."
for file in api-routes database components tests auth; do
    if [ -f "$SCRIPT_DIR/.claude/rules/$file.md" ]; then
        cp "$SCRIPT_DIR/.claude/rules/$file.md" ".claude/rules/$file.md"
        echo "  Copied: .claude/rules/$file.md"
    else
        echo "  [!] Missing: $SCRIPT_DIR/.claude/rules/$file.md"
    fi
done

# Copy settings (only if doesn't exist)
echo ""
echo "[5/7] Setting up configuration..."
if [ ! -f ".claude/settings.json" ]; then
    cp "$SCRIPT_DIR/.claude/settings.json" ".claude/settings.json"
    echo "  Created: .claude/settings.json (hooks template — edit for your stack)"
else
    echo "  Skipped: .claude/settings.json already exists"
fi

# Copy CLAUDE.md with state machine (only if doesn't exist)
if [ ! -f "CLAUDE.md" ]; then
    cp "$SCRIPT_DIR/CLAUDE-WITH-PROTOCOL.md" "CLAUDE.md"
    echo "  Created: CLAUDE.md (with protocol state machine — edit for your project)"
else
    echo "  Skipped: CLAUDE.md already exists"
fi

# Update .gitignore
echo ""
echo "[6/7] Updating .gitignore..."
if [ -f ".gitignore" ]; then
    if ! grep -q "CLAUDE.local.md" .gitignore 2>/dev/null; then
        echo "" >> .gitignore
        echo "# Claude Code local files" >> .gitignore
        echo "CLAUDE.local.md" >> .gitignore
        echo ".claude/settings.local.json" >> .gitignore
        echo "  Added Claude entries to .gitignore"
    else
        echo "  .gitignore already has Claude entries"
    fi
else
    echo "# Claude Code local files" > .gitignore
    echo "CLAUDE.local.md" >> .gitignore
    echo ".claude/settings.local.json" >> .gitignore
    echo "  Created .gitignore with Claude entries"
fi

# Verify
echo ""
echo "[7/7] Verifying setup..."
MISSING=0
for f in CLAUDE.md .claude/settings.json .claude/prompts/interview.md .claude/commands/interview.md .claude/rules/tests.md; do
    if [ ! -f "$f" ]; then
        echo "  [!] Missing: $f"
        MISSING=$((MISSING + 1))
    fi
done
if [ $MISSING -eq 0 ]; then
    echo "  All files verified."
fi

echo ""
echo "=== Setup Complete ==="
echo ""
echo "Next steps:"
echo "  1. Edit CLAUDE.md — fill in your project's stack, commands, and rules"
echo "     (the protocol state machine at the bottom is already configured)"
echo "  2. Edit .claude/settings.json — replace placeholder hooks with your lint/test commands"
echo "  3. Edit .claude/rules/*.md — adjust file paths to match your project structure"
echo "  4. Run: claude"
echo "  5. Type: /setup    (auto-detects your stack and tailors everything)"
echo "     OR manually verify: 'Read CLAUDE.md and confirm you see the rules and hooks'"
echo ""
echo "Slash commands available:"
echo "  /interview [task]     — Phase 1: Requirements interview"
echo "  /architect [folder]   — Phase 2: Design approach"
echo "  /attack [folder]      — Phase 3: Break the plan"
echo "  /write-tests [folder] — Phase 4: Write failing tests"
echo "  /build [folder]       — Phase 5: Make tests pass"
echo "  /review [folder]      — Phase 6: Fresh-eyes review"
echo "  /learn [folder]       — Phase 7: Update project intelligence"
echo "  /quick [task]         — Single-session medium protocol"
echo "  /micro [task]         — Minimal fix protocol"
echo "  /setup                — Auto-detect stack and tailor config"
echo "  /start                — Start a new project from scratch"
echo "  /deploy [folder]      — Phase 7: Pre-checks, backup, deploy, verify"
echo "  /hotfix [description] — Emergency 15-min fix protocol"
echo "  /feedback             — Audit protocol setup and suggest improvements"
echo ""
echo "Files to commit to git:"
echo "  CLAUDE.md  .claude/settings.json  .claude/prompts/  .claude/rules/  .claude/commands/"
echo ""
