<?php

// Raw XMLRPC proxy mode for external clients (Prowlarr, Sonarr, etc.)
// Options:
//   "sanitize"           — (default) allow load.* with safe params only,
//                          pass other methods as untrusted
//   "passthrough_unsafe" — send all raw XMLRPC as trusted (DANGEROUS)
//   "off"                — reject all raw XMLRPC pass-through
$XMLRPCProxy = "sanitize";

// Log raw XMLRPC proxy activity (default: true)
// Logs accepted, sanitized, and rejected methods to help diagnose
// external client integration issues.
$XMLRPCProxyLog = true;
