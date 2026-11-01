#!/usr/bin/env node
/**
 * ================================================================================
 * TLS IN FRONT OF `next start`, SO THE SUITE RUNS IN A SECURE CONTEXT. Task 9.
 * ================================================================================
 *
 * ⚠ WHY THIS EXISTS, MEASURED RATHER THAN ASSUMED. `navigator.serviceWorker` is
 * `undefined` outside a secure context, and the browsers run in a container that reaches the
 * host as `host.docker.internal` -- a hostname that is NOT on any engine's
 * secure-origin allow-list. So over plain http the whole service-worker half of this task is
 * unreachable:
 *
 *   journey-chromium  TypeError: Cannot read properties of undefined (reading 'register')
 *   journey-firefox   can't access property "register", navigator.serviceWorker is undefined
 *
 * ⚠ AND `localhost` CANNOT BE BORROWED. Docker appends `--add-host` entries AFTER the
 * default `127.0.0.1 localhost`, so `--add-host=localhost:host-gateway` leaves the container's
 * own loopback winning -- measured: `/etc/hosts` carries both and `curl http://localhost`
 * from the container answers nothing. And `--network host` does not reach the host's loopback
 * on this platform either, which `tools/browsers/bin/browsers` records.
 *
 * So the origin becomes https, which is also what production is. The certificate is
 * self-signed and generated per run into `storage/` (git-ignored); Playwright's
 * `ignoreHTTPSErrors` accepts it, and an https origin with an accepted certificate IS a
 * secure context in all three engines.
 *
 * ⚠ IT PROXIES AND DECIDES NOTHING. Every request, every header, every byte and every status
 * is forwarded unchanged in both directions. It adds no header, strips none, and caches
 * nothing -- so the bytes the suite measures are the bytes `next start` served. A proxy that
 * edited anything would make every figure a figure about the proxy.
 *
 * DEVELOPMENT AND TEST INFRASTRUCTURE ONLY. Nothing under `apps/` knows it exists, which is
 * `§1`'s rule for a local stand-in.
 *
 * USAGE: tls-proxy.mjs --port 3098 --target http://127.0.0.1:3099 --cert <pem> --key <pem>
 */
import { request } from 'node:http'
import { createServer } from 'node:https'
import { readFileSync } from 'node:fs'

function option(name) {
  const index = process.argv.indexOf(`--${name}`)

  if (index === -1 || index + 1 >= process.argv.length) {
    console.error(`tls-proxy: --${name} is required`)
    process.exit(64)
  }

  return process.argv[index + 1]
}

const port = Number.parseInt(option('port'), 10)
const target = new URL(option('target'))
const cert = readFileSync(option('cert'))
const key = readFileSync(option('key'))

if (!Number.isInteger(port) || port <= 0) {
  console.error('tls-proxy: --port must be a positive integer')
  process.exit(64)
}

const server = createServer({ cert, key }, (incoming, outgoing) => {
  const upstream = request(
    {
      hostname: target.hostname,
      port: target.port,
      path: incoming.url,
      method: incoming.method,
      // Verbatim, including `host`: Next uses it for nothing here, and rewriting it would be
      // this proxy making a decision.
      headers: incoming.headers,
    },
    (answer) => {
      outgoing.writeHead(answer.statusCode ?? 502, answer.headers)
      answer.pipe(outgoing)
    },
  )

  upstream.on('error', (error) => {
    // 502 and the reason, so a dead upstream is distinguishable from an application error.
    // Silent in the log otherwise: this process must not become a second place to read.
    if (!outgoing.headersSent) outgoing.writeHead(502, { 'Content-Type': 'text/plain' })
    outgoing.end(`tls-proxy: upstream error: ${error.message}\n`)
  })

  incoming.pipe(upstream)
})

server.listen(port, '0.0.0.0', () => {
  console.log(`tls-proxy: https://0.0.0.0:${String(port)} -> ${target.origin}`)
})
