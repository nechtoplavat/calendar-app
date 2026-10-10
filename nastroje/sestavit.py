#!/usr/bin/env python3
"""Sestaví soubory pro server z ukázky: web/rezervace/app.css a app.js.
Ukázka (ukazka/rezervace-ukazka.html) je jediný zdroj kódu – na serveru běží stejná aplikace,
jen data ukládá do databáze (atributy data-rez-* na stránce)."""
import pathlib, re, sys, zipfile
root = pathlib.Path(__file__).resolve().parent.parent
src = (root / 'ukazka' / 'rezervace-ukazka.html').read_text(encoding='utf-8')
css = re.search(r'<style>\n(.*?)</style>', src, re.S).group(1)
js = re.search(r'<script>\n(.*?)</script>', src, re.S).group(1)
out = root / 'web' / 'rezervace'
(out / 'app.css').write_text('/* Sestaveno z ukazka/rezervace-ukazka.html – neupravovat ručně */\n[hidden]{display:none!important}\n' + css, encoding='utf-8')
(out / 'app.js').write_text('/* Sestaveno z ukazka/rezervace-ukazka.html – neupravovat ručně */\n' + js, encoding='utf-8')
print('app.css', len(css), 'B · app.js', len(js), 'B')
if '--zip' in sys.argv:
    z = root / 'dist' / 'rezervace-zkusebni.zip'
    z.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(z, 'w', zipfile.ZIP_DEFLATED) as f:
        for p in sorted((root / 'web').rglob('*')):
            rel = p.relative_to(root / 'web')
            # do balíčku nepatří nastavení, databáze ani soubory pro git
            if not p.is_file() or rel.name in ('config.php', '.gitignore') or '.sqlite' in rel.name:
                continue
            if 'data' in rel.parts[:-1] and rel.name != '.htaccess':
                continue
            f.write(p, str(rel))
    print('zip', z, z.stat().st_size, 'B')
