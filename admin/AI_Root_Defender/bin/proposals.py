#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

ROOT_DIR = Path(__file__).resolve().parents[1]
if str(ROOT_DIR) not in sys.path:
    sys.path.insert(0, str(ROOT_DIR))

from agent_config import load_tool_settings
from proposal_store import ProposalStore


def build_store() -> ProposalStore:
    cfg = load_tool_settings()
    bash = cfg.get("bash", {}) if isinstance(cfg, dict) else {}
    db_path = Path(str(bash.get("db_path", "./bh/bash_history.db"))).resolve()
    mirror = bool(bash.get("proposal_jsonl_mirror", True))
    jsonl_dir = Path(str(bash.get("proposal_jsonl_dir", "./bh/events"))).resolve()
    return ProposalStore(db_path=db_path, jsonl_mirror=mirror, jsonl_dir=jsonl_dir)


def cmd_list(limit: int) -> int:
    store = build_store()
    rows = store.list_pending(limit=limit)
    if not rows:
        print("No pending proposals.")
        return 0
    for row in rows:
        print(f"#{row['id']} [{row['risk_level']}] {row['command_text']} :: {row['operator_summary']}")
    return 0


def cmd_approve(proposal_id: int, who: str) -> int:
    store = build_store()
    if store.approve(proposal_id, approved_by=who):
        print(f"Approved proposal #{proposal_id}")
        return 0
    print(f"Proposal #{proposal_id} not found or not pending")
    return 1


def cmd_reject(proposal_id: int, reason: str, who: str) -> int:
    store = build_store()
    if store.reject(proposal_id, reason=reason, rejected_by=who):
        print(f"Rejected proposal #{proposal_id}")
        return 0
    print(f"Proposal #{proposal_id} not found or not pending")
    return 1


def cmd_propose(command: str, cwd: str, risk: str, summary: str) -> int:
    store = build_store()
    proposal_id = store.create_proposal(
        command_text=command,
        cwd=cwd,
        risk_level=risk,
        operator_summary=summary,
        metadata={"source": "manual_cli"},
    )
    print(json.dumps({"proposal_id": proposal_id, "status": "proposed"}, ensure_ascii=True))
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Proposal queue terminal helper")
    sub = parser.add_subparsers(dest="cmd", required=True)

    p_list = sub.add_parser("list")
    p_list.add_argument("--limit", type=int, default=25)

    p_approve = sub.add_parser("approve")
    p_approve.add_argument("id", type=int)
    p_approve.add_argument("--by", default="operator")

    p_reject = sub.add_parser("reject")
    p_reject.add_argument("id", type=int)
    p_reject.add_argument("--reason", default="")
    p_reject.add_argument("--by", default="operator")

    p_propose = sub.add_parser("propose")
    p_propose.add_argument("--command", required=True)
    p_propose.add_argument("--cwd", default="/web")
    p_propose.add_argument("--risk", default="medium")
    p_propose.add_argument("--summary", default="")

    args = parser.parse_args()
    if args.cmd == "list":
        return cmd_list(args.limit)
    if args.cmd == "approve":
        return cmd_approve(args.id, args.by)
    if args.cmd == "reject":
        return cmd_reject(args.id, args.reason, args.by)
    if args.cmd == "propose":
        return cmd_propose(args.command, args.cwd, args.risk, args.summary)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
