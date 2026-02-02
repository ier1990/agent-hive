## install


mkdir -p /web/private/bin /web/private/conf /web/private/logs /web/private/db /web/private/db/memory /web/private/scripts

sudo chown -R samekhi:www-data /web
sudo chmod 2770 /web/private/bin /web/private/scripts

Keep admin writes non-root when possible
sudo chmod 2770 /web/private/db /web/private/db/memory



/web/private/bin/worker.py ← one script per server

/web/private/conf/worker.json ← server identity + peer URLs

/web/private/logs/worker.log

/web/private/db/*.db (if needed)

## run

Worker responsibilities (single script does both)

Your one worker.py should do 2 loops:

Heartbeat (at least hourly; can be every 5 minutes)

Job pull (every 30–60 seconds) from DO “main queue host”

So DO doesn’t have to push to you; you pull.

That satisfies your “one secure common port” plan:

All worker traffic is outbound HTTPS to DO (:443)

No inbound ports needed at home/jville

Cron (simple, reliable)

Instead of running it every minute via cron, run it as a service or a cron “keepalive”.

Option A (recommended): systemd service

/etc/systemd/system/ai-worker.service:

[Unit]
Description=AI Worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=samekhi
Environment=PRIVATE_ROOT=/web/private
Environment=MESH_TOKEN=change_me
ExecStart=/usr/bin/python3 /web/private/bin/worker.py
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target


Enable:

sudo systemctl daemon-reload
sudo systemctl enable --now ai-worker

Option B: cron every minute (works, but clunkier)
* * * * * flock -n /tmp/ai-worker.lock /usr/bin/python3 /web/private/bin/worker.py --once >> /web/private/logs/worker.log 2>&1


(With --once meaning it runs one iteration and exits.)

“One worker script per server” naming convention

Keep it identical on every box:

/web/private/bin/worker.py

But config differentiates identity:

/web/private/conf/worker.json:

{
  "name": "do2",
  "role": "dispatcher",
  "home": "https://www.web-ministry.com",
  "queue_host": "https://www.web-ministry.com",
  "peers": [
    "https://api.iernc.net/v1/receiving/",
    "https://jville.example.com/v1/receiving/"
  ],
  "capabilities": {
    "inference": false
  }
}


On the 3090 worker:

{
  "name": "home-3090",
  "role": "worker",
  "queue_host": "https://www.web-ministry.com",
  "capabilities": {
    "inference": true,
    "models": ["..."],
    "gpu": "3090"
  }
}


Same script, different config.

Deployment flow (ties into your rsync pipeline)

.142 is authoring box

.191 is relay

DO pulls repo into /.admin/repo (staging)

Deploy step copies:

UI → /.admin/live

worker → /web/private/bin/worker.py

Example deploy on DO:

rsync -a --delete /home/jjf1995/public_html/.admin/repo/workers/worker.py /web/private/bin/worker.py
chmod +x /web/private/bin/worker.py

Bottom line answer to your question

✅ Workers live and run from /web/private/bin, not from /admin or /.admin.
✅ /.admin is just the control panel UI (optional).
✅ One script per server is perfect — let config decide what it does.

If you paste what endpoints you already have under /v1/ for jobs (e.g. /v1/request/, /v1/receiving/, /v1/response/), I’ll draft the exact worker.py skeleton with:

heartbeat POST

job poll GET/POST

result POST

retry + backoff

lockfile

logs to /web/private/logs/worker.log






.142 /web/html/.admin/repo
to /191/web/html/.admin/repo
rsync from to
rsync -a samekhi@192.168.0.142:/web/html/.admin/repo/ /web/html/.admin/repo/

Host do-panel2
  HostName 167.172.26.150
  User samekhi
  Port 22
  IdentityFile ~/.ssh/id_ed25519_do2
  IdentitiesOnly yes

from .191 /web/html/.admin/repo
to do-panel2:/home/jjf1995/public_html/.admin/repo/

sudo rsync -a --delete samekhi@192.168.0.142:/web/html/.admin/repo/ /web/html/.admin/repo/

NO deletes!!!!!!!!!
rsync -a samekhi@192.168.0.142:/web/html/.admin/repo/ /web/html/.admin/repo/

user samekhi

//required password
rsync -a \
  --exclude '.git/' \
  samekhi@192.168.0.142:/web/html/.admin/repo/ \
  /web/html/.admin/repo/

//no  password
rsync -az \
  --exclude '.git/' \
  /web/html/.admin/repo/ \
  do-panel2:/home/jjf1995/public_html/.admin/repo/

