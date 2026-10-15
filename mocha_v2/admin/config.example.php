<?php
// MODEL: copiază acest fișier ca config.php și scrie parola între ghilimele.
// Folosește minimum 10 caractere. Cât timp rămâne goală, autentificarea este blocată.
const ADMIN_PASSWORD = '';


// Dimensiunea maximă a unei imagini încărcate (MB).
const MAX_UPLOAD_MB = 5;

// Dimensiunea maximă a video-ului ofertei (MB). Serverul poate avea o limită proprie mai mică
// (upload_max_filesize / post_max_size din cPanel → Select PHP Version → Options).
const MAX_VIDEO_MB = 60;

// Câte copii de siguranță ale meniului se păstrează în data/backups/.
const BACKUPS_TO_KEEP = 30;
