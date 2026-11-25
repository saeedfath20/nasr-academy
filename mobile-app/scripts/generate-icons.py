"""Export unchanged Nasr artwork to platform launcher asset sizes (Pillow)."""
from pathlib import Path
from PIL import Image, ImageOps
import json, math
app=Path(__file__).resolve().parents[1]
logo=Image.open(app.parent/'nasr-academy/assets/nasr-logo.png').convert('RGBA')
logo=logo.crop(logo.getbbox())
def canvas(size, max_diagonal=None, height_fraction=.82, transparent=False):
    bound=math.hypot(*logo.size)
    factor=(size*max_diagonal/bound if max_diagonal else size*height_fraction/max(logo.size))
    artwork=logo.resize((max(1,round(logo.width*factor)),max(1,round(logo.height*factor))),Image.Resampling.LANCZOS)
    result=Image.new('RGBA',(size,size),(255,255,255,0 if transparent else 255))
    result.alpha_composite(artwork,((size-artwork.width)//2,(size-artwork.height)//2))
    return result if transparent else result.convert('RGB')
resources=app/'resources';resources.mkdir(exist_ok=True)
canvas(1024).save(resources/'icon.png')
android=app/'android/app/src/main/res'
for density,scale in [('mdpi',1),('hdpi',1.5),('xhdpi',2),('xxhdpi',3),('xxxhdpi',4)]:
    folder=android/('mipmap-'+density);folder.mkdir(exist_ok=True)
    # Keep artwork inside the adaptive icon's central 66dp circle.
    canvas(round(108*scale),max_diagonal=64/108,transparent=True).save(folder/'nasr_launcher_foreground.png')
    for name in ['nasr_launcher','nasr_launcher_round']:
        canvas(round(48*scale),max_diagonal=.89).save(folder/(name+'.png'))
    for old in ['ic_launcher.png','ic_launcher_round.png','ic_launcher_foreground.png']:
        (folder/old).unlink(missing_ok=True)
color=android/'values/nasr_icon_background.xml'
color.write_text('<?xml version="1.0" encoding="utf-8"?>\n<resources><color name="nasr_icon_background">#FFFFFF</color></resources>\n')
for name in ['nasr_launcher','nasr_launcher_round']:
    (android/'mipmap-anydpi-v26'/(name+'.xml')).write_text('<?xml version="1.0" encoding="utf-8"?>\n<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android"><background android:drawable="@color/nasr_icon_background"/><foreground android:drawable="@mipmap/nasr_launcher_foreground"/></adaptive-icon>\n')
for old in ['ic_launcher.xml','ic_launcher_round.xml']:
    (android/'mipmap-anydpi-v26'/old).unlink(missing_ok=True)
manifest=app/'android/app/src/main/AndroidManifest.xml'
manifest.write_text(manifest.read_text().replace('@mipmap/ic_launcher_round','@mipmap/nasr_launcher_round').replace('@mipmap/ic_launcher','@mipmap/nasr_launcher'))
ios=app/'ios/App/App/Assets.xcassets/AppIcon.appiconset'
for old in ios.glob('*.png'):old.unlink()
canvas(1024).save(ios/'Nasr-AppIcon-1024.png')
(ios/'Contents.json').write_text(json.dumps({'images':[{'filename':'Nasr-AppIcon-1024.png','idiom':'universal','platform':'ios','size':'1024x1024'}],'info':{'author':'xcode','version':1}},indent=2)+'\n')
print('Exported Nasr logo: 5 Android densities, adaptive icons and iOS 1024px asset.')
