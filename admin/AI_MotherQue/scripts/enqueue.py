#!/usr/bin/env python3
# /web/html/admin/AI/scripts/enqueue.py
import argparse, json, os, sys
from mq import MotherQueue

DB = os.environ.get("MOTHER_QUEUE_DB", "/web/private/db/memory/mother_queue.db")

def main():
    parser = argparse.ArgumentParser(description='Enqueue a job to mother_queue')
    parser.add_argument('--queue', default='default', help='Queue name')
    parser.add_argument('--name', required=True, help='Job name')
    parser.add_argument('--payload', default='{}', help='Job payload as JSON string')
    parser.add_argument('--priority', type=int, default=100, help='Job priority')
    parser.add_argument('--max-attempts', type=int, default=5, help='Max retry attempts')
    
    args = parser.parse_args()
    
    try:
        payload = json.loads(args.payload)
    except json.JSONDecodeError as e:
        print(f"Invalid JSON payload: {e}", file=sys.stderr)
        sys.exit(1)
    
    mq = MotherQueue(DB)
    job_id = mq.enqueue(
        queue=args.queue,
        name=args.name,
        payload=payload,
        priority=args.priority,
        max_attempts=args.max_attempts
    )
    print(f"Job enqueued: {job_id}")
    return 0

if __name__ == '__main__':
    sys.exit(main())
