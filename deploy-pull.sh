#!/bin/bash
# Автовыкладка лендинга Альянс на хостинге Timeweb. Запускается кроном панели раз в 5 минут.
#
# Источник: ветка prod публичного репозитория trutnevde/alyans-preview, её кладёт scripts/publish-prod.ps1:
#   version.txt     номер версии
#   site/           собранный сайт, становится public_html
#   deploy-pull.sh  этот скрипт, крон каждый раз берёт его свежую копию
#
# Если версия новая: скачивает архив ветки, проверяет его и подменяет public_html целиком.
# Прошлая версия остаётся в public_html.old, откат:
#   mv public_html public_html.bad && mv public_html.old public_html && rm .deployed-version
# lead.config.php и leads/ рядом с public_html не трогает. Журнал в deploy.log.
set -u
SITE="$HOME/alyans-dance.ru"
REPO="trutnevde/alyans-preview"
BRANCH="prod"
LOG="$SITE/deploy.log"
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

cd "$SITE" || exit 1
if command -v flock >/dev/null 2>&1; then
  exec 9>"$SITE/.deploy.lock"
  flock -n 9 || exit 0
fi

remote=$(curl -fsS --max-time 30 "https://raw.githubusercontent.com/$REPO/$BRANCH/version.txt") || { log "не удалось получить version.txt"; exit 1; }
current=$(cat "$SITE/.deployed-version" 2>/dev/null || true)
if [ -n "$remote" ] && [ "$remote" = "$current" ]; then exit 0; fi

tmp="$SITE/.deploy-tmp"
rm -rf "$tmp" && mkdir -p "$tmp/x" || exit 1
# около 14 МБ, канал до GitHub бывает медленным; второй запуск крона ждёт блокировку и не мешает
if ! curl -fsSL --max-time 900 --retry 2 -o "$tmp/prod.tgz" "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH"; then
  log "не удалось скачать архив ветки $BRANCH"; rm -rf "$tmp"; exit 1
fi
if ! tar -xzf "$tmp/prod.tgz" -C "$tmp/x" --strip-components=1; then
  log "архив не распаковался"; rm -rf "$tmp"; exit 1
fi

# version.txt и архив отдаются с разных адресов GitHub, кэш может отставать: ждём следующего запуска
got=$(cat "$tmp/x/version.txt" 2>/dev/null || true)
if [ "$got" != "$remote" ]; then rm -rf "$tmp"; exit 0; fi

if [ ! -f "$tmp/x/site/index.html" ] || [ ! -f "$tmp/x/site/.htaccess" ]; then
  log "в версии $remote нет index.html или .htaccess, не выкладываю"; rm -rf "$tmp"; exit 1
fi

rm -rf "$SITE/public_html.old"
if [ -d "$SITE/public_html" ]; then mv "$SITE/public_html" "$SITE/public_html.old"; fi
mv "$tmp/x/site" "$SITE/public_html"
echo "$remote" > "$SITE/.deployed-version"
rm -rf "$tmp"
log "выложена версия $remote"
