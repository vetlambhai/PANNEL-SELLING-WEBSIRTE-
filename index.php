<?php
/**
 * ============================================================
 *  SG PANEL — index.php
 *  Domain: keysmaster.shop
 * ============================================================
 *  The public landing page is the KEY STORE (keys.php).
 *  Root URL and index.php both redirect to /keys so that
 *  visitors land directly on the Free Fire Key Store.
 * ============================================================
 */
header('Location: keys.php', true, 301);
exit();
