<?php
/**
 * GET /api/wa/pending.php?limit=20
 * بيحجز رسايل الواتساب الجاهزة (atomic) ويرجّعها لـ n8n:
 *   { "messages": [ {id, phone, text, media_url, media_type}, … ] }   (array فاضي لو مفيش)
 */
require_once __DIR__ . '/_boot.php';

$limit = (int) ($_GET['limit'] ?? 20);
wa_json(['messages' => wa_claim_pending($limit > 0 ? $limit : 20)]);
