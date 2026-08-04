/**
 * =============================================================================
 *  ANONYMOUX LIVECAST — Backend
 * =============================================================================
 *  This server has exactly one job with respect to Decart: mint short-lived
 *  client tokens using the official @decartai/sdk, so the permanent
 *  DECART_API_KEY never reaches the browser. The video itself never touches
 *  this server — Lucy 2.5 Realtime streams over WebRTC directly between the
 *  browser and Decart's edge network once the browser has a client token.
 *
 *  Token minting uses the SDK's documented `client.tokens.create()` method
 *  (see https://docs.platform.decart.ai/sdks/javascript-realtime — "Client-side
 *  authentication"). We never construct Decart URLs or call fetch() against
 *  Decart ourselves; the SDK owns that.
 * =============================================================================
 */

import 'dotenv/config';
import express from 'express';
import cors from 'cors';
import { createDecartClient } from '@decartai/sdk';

const PORT = process.env.PORT || 10000;
const DECART_API_KEY = process.env.DECART_API_KEY || '';
const DEFAULT_MODEL = 'lucy-2.5';
const TOKEN_TTL_SECONDS = 300; // 5 minutes — plenty for a session to establish

// Parse ALLOWED_ORIGINS into an array; empty means "same origin only" (cors default).
const allowedOrigins = (process.env.ALLOWED_ORIGINS || '')
  .split(',')
  .map((o) => o.trim())
  .filter(Boolean);

const app = express();
app.use(express.json());

app.use(
  cors(
    allowedOrigins.length > 0
      ? { origin: allowedOrigins }
      : {} // permissive default for same-origin/local dev; tighten via ALLOWED_ORIGINS in production
  )
);

// Serve the frontend as static files.
app.use(express.static('.', { index: 'index.html' }));

// Decart client, constructed once at boot using the permanent secret key.
// This client instance is only ever used server-side.
let decartClient = null;
function getDecartClient() {
  if (!DECART_API_KEY) return null;
  if (!decartClient) {
    decartClient = createDecartClient({ apiKey: DECART_API_KEY });
  }
  return decartClient;
}

/**
 * GET /health
 * Lets the frontend (and Render's health check) confirm the server is up
 * and whether a Decart key has been configured, without ever revealing it.
 */
app.get('/health', (req, res) => {
  res.json({
    ok: true,
    configured: Boolean(DECART_API_KEY),
    model: DEFAULT_MODEL,
  });
});

/**
 * POST /token
 * Mints a short-lived client token via the official SDK's tokens.create().
 * Body (optional): { model?: string }
 * Response: { apiKey, expiresAt, model }
 */
app.post('/token', async (req, res) => {
  const client = getDecartClient();

  if (!client) {
    return res.status(500).json({
      ok: false,
      error: 'not_configured',
      message: 'Server is missing DECART_API_KEY. Set it in your environment and restart.',
    });
  }

  const requestedModel =
    typeof req.body?.model === 'string' && req.body.model.trim()
      ? req.body.model.trim()
      : DEFAULT_MODEL;

  try {
    const token = await client.tokens.create({
      expiresIn: TOKEN_TTL_SECONDS,
      allowedModels: [requestedModel],
    });

    return res.json({
      ok: true,
      apiKey: token.apiKey,
      expiresAt: token.expiresAt,
      model: requestedModel,
    });
  } catch (err) {
    console.error('[Decart] token mint failed:', err?.message || err);
    return res.status(502).json({
      ok: false,
      error: 'token_mint_failed',
      message:
        'Decart rejected the token request. Confirm DECART_API_KEY is a valid, active secret key.',
    });
  }
});

app.listen(PORT, () => {
  console.log(`Anonymoux Livecast server running on port ${PORT}`);
  console.log(`Decart key configured: ${Boolean(DECART_API_KEY)}`);
});
