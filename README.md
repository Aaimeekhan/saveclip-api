# SaveClip API — Render (free Docker web service)

Backend only. The page (index.html) stays on Hostinger.

## Deploy
1. Create a GitHub repo (e.g. `saveclip-api`) and upload these files: Dockerfile, docker-start.sh, api.php, index.php, render.yaml, .gitignore
2. Render → New → Blueprint → pick the repo (render.yaml sets everything), or New → Web Service → Docker, plan Free.
3. Env vars: ALLOWED_ORIGINS = https://iamirsaleem.com,https://www.iamirsaleem.com
4. Service URL will be like https://saveclip-api.onrender.com — test it opens `{"ok":true,...}`

## Hostinger page
In index.html set: `const API = 'https://saveclip-api.onrender.com/api.php';`

Notes: free plan sleeps after 15 min idle (first request ~1 min). Disk is temporary — fine, files are deleted after download.
YouTube may block Render's datacenter IPs ("Sign in to confirm you're not a bot"); other platforms usually work. A cookies.txt (COOKIES_FILE) can help.
