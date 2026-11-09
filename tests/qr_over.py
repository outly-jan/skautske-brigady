# Přečte QR kódy vygenerované tests/qr_generuj.php nezávislou čtečkou (zxing-cpp) a porovná text.
import glob, os, sys
import zxingcpp
from PIL import Image
d = sys.argv[1]
ok = bad = 0
for f in sorted(glob.glob(os.path.join(d, 'qr*.png')), key=lambda x: int(os.path.basename(x)[2:-4])):
    exp = open(f[:-4] + '.txt', encoding='utf-8').read()
    r = zxingcpp.read_barcodes(Image.open(f))
    got = r[0].text if r else None
    if got == exp:
        ok += 1
    else:
        bad += 1
        print('CHYBA', os.path.basename(f), len(exp.encode()), repr(got)[:60])
print(f'přečteno správně: {ok}, chyb: {bad}')
sys.exit(1 if bad else 0)
