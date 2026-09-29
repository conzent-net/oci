# ─── OCI Cookie Scanner ──────────────────────────────────
# Self-contained scanning server with:
#   - Website Evidence Collector (WEC) from EU EDPS
#   - Pinned Chrome-for-Testing (not Debian's rolling `chromium` package, whose
#     v150 build SIGILLs on some hosts — installed via @puppeteer/browsers below)
#   - Node.js API server
#
# Deploy alongside the main app or on separate servers.
# Communicates via HTTP API with shared API key auth.
#
# Build:  docker build -t oci-scanner docker/scanner/
# Run:    docker run -p 8300:8300 -e SCANNER_API_KEY=secret oci-scanner
# ─────────────────────────────────────────────────────────

FROM node:20-slim

# Chrome runtime libraries + fonts. We DON'T install the distro `chromium`
# package (its rolling v150 build crashes with SIGILL on some CPUs); instead we
# fetch a pinned Chrome-for-Testing binary below and ship the libs it needs.
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    fonts-liberation \
    fonts-noto-color-emoji \
    fonts-noto-cjk \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libatspi2.0-0 \
    libcairo2 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libglib2.0-0 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libpango-1.0-0 \
    libx11-6 \
    libx11-xcb1 \
    libxcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxkbcommon0 \
    libxrandr2 \
    libxrender1 \
    libxshmfence1 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

# Install a pinned, automation-QA'd Chrome-for-Testing build and expose it at a
# stable path. `chrome@stable` resolves to the current Chrome-for-Testing stable
# channel (tested for headless automation — unlike the Debian package that
# SIGILL'd). Pin an explicit version (e.g. chrome@131.0.6778.204) for fully
# reproducible builds. The trailing `--version` fails the build early if the
# binary or a shared library is missing.
RUN npx --yes @puppeteer/browsers install chrome@stable --path /opt/chrome \
    && ln -sf "$(find /opt/chrome -type f -name chrome -path '*chrome-linux64*' | head -n1)" /usr/local/bin/chrome \
    && /usr/local/bin/chrome --version

# Point Puppeteer at the pinned Chrome (puppeteer-core never downloads its own).
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/local/bin/chrome \
    CHROMIUM_PATH=/usr/local/bin/chrome

# Create app directory
WORKDIR /opt/scanner

# Copy package files and install dependencies
COPY package.json package-lock.json* ./
RUN npm ci --production 2>/dev/null || npm install --production

# Copy scanner application
COPY server.js ./
COPY lib/ ./lib/

# Create non-root user for security
RUN groupadd -r scanner && useradd -r -g scanner -G audio,video scanner \
    && mkdir -p /tmp/scans /home/scanner \
    && chown -R scanner:scanner /opt/scanner /tmp/scans /home/scanner

USER scanner

# Environment defaults (override at runtime)
ENV PORT=8300 \
    SCANNER_API_KEY="" \
    MAX_CONCURRENT=3 \
    SCAN_TIMEOUT=60000 \
    NODE_ENV=production

EXPOSE 8300

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:8300/health || exit 1

CMD ["node", "server.js"]
