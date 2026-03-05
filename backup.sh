#!/bin/bash
# SomBazar Otomatik Yedekleme Scripti
# Cron: 0 2 * * * /bin/bash /var/www/sombazar/backup.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/sombazar"
DB_NAME="sombazar"
DB_USER="root"
DB_PASS=""
SITE_DIR="/var/www/sombazar"
REMOTE="user@backup-server:/backups/sombazar"  # SSH remote

mkdir -p "$BACKUP_DIR/db"
mkdir -p "$BACKUP_DIR/files"

# 1. Veritabanı yedeği
echo "[$DATE] Veritabanı yedekleniyor..."
mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$BACKUP_DIR/db/sombazar_$DATE.sql.gz"

# 2. Yüklenen dosyalar yedeği (fotoğraflar)
echo "[$DATE] Dosyalar yedekleniyor..."
tar -czf "$BACKUP_DIR/files/uploads_$DATE.tar.gz" "$SITE_DIR/uploads/"

# 3. Uzak sunucuya gönder (opsiyonel - SSH kurulu olmalı)
# rsync -az "$BACKUP_DIR/" "$REMOTE/" 2>/dev/null || echo "Uzak yedek başarısız"

# 4. 30 günden eski yedekleri sil
find "$BACKUP_DIR/db" -name "*.gz" -mtime +30 -delete
find "$BACKUP_DIR/files" -name "*.tar.gz" -mtime +30 -delete

echo "[$DATE] Yedekleme tamamlandı."
echo "[$DATE] Yedekleme tamamlandı." >> /var/log/sombazar_backup.log

# ═══ CRON JOB KURULUMU ════════════════════════════════════════
# Aşağıdaki satırları `crontab -e` komutuyla ekleyin:
#
# Her saat — offer/plan expire ve cleanup:
# 0 * * * * php /var/www/html/api/cron.php >> /var/log/sombazar_cron.log 2>&1
#
# Gece yarısı — sitemap ping:
# 0 0 * * * php /var/www/html/api/cron.php --task=sitemap_ping >> /var/log/sombazar_cron.log 2>&1
#
# Her gece — listing expire (30 gün):
# 0 2 * * * php /var/www/html/api/cron.php --task=expire_listings >> /var/log/sombazar_cron.log 2>&1
#
# Haftalık — temp dosya temizliği:
# 0 3 * * 0 php /var/www/html/api/cron.php --task=cleanup_temp >> /var/log/sombazar_cron.log 2>&1
