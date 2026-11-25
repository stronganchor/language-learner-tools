FROM node:22-alpine
RUN apk add --no-cache chromium nss-tools bash ca-certificates font-freefont
COPY tests/interop/moodle/browser-boot.sh /usr/local/bin/browser-interop
COPY tests/e2e/node_modules/playwright /node_modules/playwright
COPY tests/e2e/node_modules/playwright-core /node_modules/playwright-core
COPY tests/e2e/node_modules/@playwright/test /node_modules/@playwright/test
RUN chmod +x /usr/local/bin/browser-interop
ENTRYPOINT ["/usr/local/bin/browser-interop"]
