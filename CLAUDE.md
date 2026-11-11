# Pravidla pro práci na tomto projektu

Samostatný WordPress plugin **Skautské akce** pro web skautchlumec.cz —
přehled plánovaných akcí střediska (shortcode `[skautske_akce]`).

## Napojení na plugin Skautské brigády
- Brigády se čtou **jen z databáze** (příspěvky `brigada` + post meta), nikdy
  přes funkce `sb_*` pluginu brigád — přehled musí fungovat i při aktualizaci
  nebo deaktivaci brigád
- Všechny klíče, na kterých napojení závisí, jsou na jednom místě:
  `ska_brigady_klice()` ve `skautske-akce.php`
- Kontrolu hlídá workflow `kompatibilita-brigady.yml` (denně a při pushi) a
  skript `tests/kontrola_brigad.php`; když selže, uprav `ska_brigady_klice()`

## Workflow
- Po každé smysluplné změně: commit → push → PR → squash merge do `main`
- Commity a PR popisky piš česky

## Před každým mergem — povinná kontrola
Před vytvořením PR vždy spusť:
```
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php tests/test_plugin.php
```
Pokud PHP hlásí chybu nebo testy selžou, nesmíš mergovat.

## Deploy
- Po mergi do `main` se automaticky spustí GitHub Actions a zavolá
  `https://skautchlumec.cz/wp-content/plugins/skautske-akce/deploy-webhook.php`
- Token je uložen v GitHub secret `DEPLOY_SECRET` tohoto repozitáře
- Soubor `deploy-webhook.php` na serveru má token nastaven ručně a webhook
  sám sebe nikdy nestahuje — změny jeho kódu je nutné nahrát na server ručně
  (FTP), nikdy v něm přes git neměň `CHANGE_ME`
- Webhook nestahuje `.github/`, `tests/`, `CLAUDE.md` ani sám sebe; vše ostatní
  v repu se nasadí do `wp-content/plugins/skautske-akce/`
- Na FTP je správná cesta `/public_html/wp-content/plugins/skautske-akce/`
