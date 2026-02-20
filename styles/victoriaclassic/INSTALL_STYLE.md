# VictoriaClassic Installation

## 1) Ensure style files exist

The style folder must be present at:

`~/VictoriaPark/victoriapark/styles/victoriaclassic`

## 2) Install from ACP

phpBB 3.3:

`ACP > Customise > Style management > Styles`

phpBB 3.2:

`ACP > Customise > Styles`

Then:

1. Find `VictoriaClassic` under "Uninstalled styles".
2. Click `Install`.
3. Click `Details` for `VictoriaClassic`.
4. Set `Use style` to enabled.
5. If desired, set as board default style:
   `ACP > General > Board configuration > Board settings > Default style`.

## 3) Purge cache

ACP:

`ACP index > Run now` next to `Purge the cache`

CLI (Docker local project):

```bash
cd ~/VictoriaPark/victoriapark
COMPOSE_PROJECT_NAME=victoriapark-local docker compose -f docker-compose.yml -f docker-compose.local.yml exec -T php php ./bin/phpbbcli.php cache:purge
```

## 4) Verify style

1. Open board index.
2. Confirm fixed-width layout, denser rows, zebra topic lists, compact pagination, and right rail blocks.
3. Check mobile width: right rail should stack below main content.
