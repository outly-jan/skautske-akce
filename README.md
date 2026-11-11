# Skautské akce

WordPress plugin s přehledem plánovaných akcí skautského střediska (skautchlumec.cz).

## Co plugin umí

- **Tabulka akcí na webu** — shortcode `[skautske_akce]`: datum, čas, popis a odkaz na podrobnosti. Na mobilu se řádky skládají pod sebe.
- **Jednodenní i vícedenní akce** — datum, nebo rozmezí dat (např. *pá 13. 11. – ne 15. 11. 2026*).
- **Nepovinný čas od–do** — lze vyplnit oba časy, jen začátek, jen konec, nebo nic. U vícedenní akce jde o začátek prvního a konec posledního dne (*pá 18:00 – ne 12:00*).
- **Odkaz na podrobnosti** (nepovinný) — externí odkazy se otevírají v novém okně.
- **Rozklikávací adresy v popisu** — adresy `https://…` a `www.…` v popisu akce i brigády (např. v místě konání) jsou odkazy; odkazy na mapy (mapy.cz, mapy.com, Google Maps) se zobrazí jako „mapa“.
- **Automatické mizení** — akce se zobrazuje do svého dne včetně, vícedenní akce do posledního dne. Probíhající akce jsou zvýrazněné.
- **Brigády** — budoucí brigády z pluginu [Skautské brigády](https://github.com/outly-jan/skautske-brigady) se do přehledu načítají automaticky (název, místo, čas, předběžný termín) s odkazem *Přihlásit se* na stránku s brigádami.

## Správa akcí

Ve wp-adminu menu **Plánované akce** (jen administrátoři, capability `manage_options`):

- přidání, úprava a smazání akce,
- přehled všech naplánovaných akcí včetně brigád (brigády jen pro čtení — upravují se v pluginu Skautské brigády),
- proběhlé akce (na webu už nejsou; lze je smazat jedním tlačítkem),
- **Napojení na Skautské brigády**: zapnutí/vypnutí brigád v přehledu, označení brigád (výchozí „Brigáda“), vlastní odkaz u brigád a diagnostika (kolik brigád plugin našel, zda je plugin brigád aktivní).

## Shortcode

| Zápis | Význam |
|---|---|
| `[skautske_akce]` | všechny budoucí akce včetně brigád |
| `[skautske_akce limit="5"]` | jen nejbližších 5 akcí |
| `[skautske_akce brigady="ne"]` | bez brigád |
| `[skautske_akce prazdne="Nic nás nečeká."]` | vlastní text, když nejsou žádné akce |

## Kompatibilita s pluginem Skautské brigády

Aby přehled fungoval i během a po aktualizaci pluginu brigád:

- brigády se čtou **přímo z databáze WordPressu** (příspěvky typu `brigada` a jejich post meta), ne přes funkce pluginu brigád — přehled tak funguje, i když je plugin brigád zrovna aktualizovaný nebo deaktivovaný,
- všechny klíče, na kterých napojení závisí, jsou na jednom místě ve funkci `ska_brigady_klice()` (lze je změnit i filtrem `ska_brigady_klice`),
- chyba při čtení brigád nikdy neshodí přehled — akce se zobrazí bez brigád a chyba se zapíše do logu PHP,
- workflow **Kompatibilita se Skautskými brigádami** každý den (a při každém pushi) stáhne aktuální kód pluginu brigád a ověří, že stále ukládá brigády pod očekávanými klíči. Když ne, běh selže a GitHub pošle e-mail; pak stačí upravit `ska_brigady_klice()`.

Používané údaje brigád: `datum_brigady` (Y-m-d), `cas_brigady` (volný text — z něj se vyčte začátek, případně i konec), `delka_brigady` (dopočet konce), `misto_konani`, `datum_predbezne`, `cas_predbezne`; odkaz na stránku se shortcodem `[sb_prehled_brigad]`, `[prehled_brigad]` nebo `[moje_brigady]`.

## Data

Akce se ukládají do option `ska_akce`, nastavení do `ska_nastaveni`. Při smazání pluginu ve wp-adminu se data odstraní (`uninstall.php`).

## Požadavky

- WordPress 6.0+, PHP 8.0+, žádné externí závislosti

## Instalace (poprvé)

1. Nahraj přes FTP soubory `skautske-akce.php`, `uninstall.php` a `deploy-webhook.php` do `/public_html/wp-content/plugins/skautske-akce/`.
2. V `deploy-webhook.php` **na serveru** nahraď `CHANGE_ME` náhodným tokenem a stejný token ulož do GitHub secret `DEPLOY_SECRET` tohoto repozitáře.
3. Ve wp-adminu plugin aktivuj a vlož shortcode `[skautske_akce]` na stránku.

## Nasazení

Po každém pushi do `main` GitHub Actions zkontroluje syntaxi PHP, spustí testy a zavolá `deploy-webhook.php`, který stáhne soubory daného commitu z GitHubu (kromě `.github/`, `tests/`, `CLAUDE.md` a sebe sama).

## Testy

```
php tests/test_plugin.php
php tests/kontrola_brigad.php cesta/k/skautske-brigady.php
```

## Autor

Honza Outlý
