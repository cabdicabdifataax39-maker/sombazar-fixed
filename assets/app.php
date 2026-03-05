<?php
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=3600');
readfile(__DIR__ . '/app.js');
