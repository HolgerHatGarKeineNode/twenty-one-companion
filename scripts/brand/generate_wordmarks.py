#!/usr/bin/env python3
"""
Generiert die sprachabhängigen BRAND-Wortmarken als Blade-Partials
(resources/views/components/brand/wordmark/<slug>.blade.php) per
Glyphen-Rekombination aus den marken-eigenen Outlines.

Hintergrund/Details: siehe Memory brand-wordmark-generation.
Aufruf:  python3 scripts/brand/generate_wordmarks.py [--proof]
"""
import re, sys, os, numpy as np
from fontTools.ttLib import TTFont
from fontTools.pens.recordingPen import RecordingPen
from fontTools.varLib import instancer

ROOT=os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PORTAL_SVG="/home/user/Code/einundzwanzig-app/public/img/einundzwanzig-square.svg"
FONTS=os.path.join(ROOT,"scripts/brand/fonts")  # geliehene Glyphen (S/J)
OUT=os.path.join(ROOT,"resources/views/components/brand/wordmark")
CAP=696.0

# ---------- SVG path parser (absolute M L H V C Q Z) ----------
_tok=re.compile(r'([MLHVCQZ])|(-?\d*\.?\d+)')
def parse_svg(d):
    cmds=[m.group(1) if m.group(1) else float(m.group(2)) for m in _tok.finditer(d)]
    contours=[];cur=[];x=y=sx=sy=0.0;i=0
    def cub(p0,p1,p2,p3,n=40):
        t=np.linspace(0,1,n)
        return list(zip((1-t)**3*p0[0]+3*(1-t)**2*t*p1[0]+3*(1-t)*t*t*p2[0]+t**3*p3[0],
                        (1-t)**3*p0[1]+3*(1-t)**2*t*p1[1]+3*(1-t)*t*t*p2[1]+t**3*p3[1]))
    def quad(p0,p1,p2,n=28):
        t=np.linspace(0,1,n)
        return list(zip((1-t)**2*p0[0]+2*(1-t)*t*p1[0]+t*t*p2[0],
                        (1-t)**2*p0[1]+2*(1-t)*t*p1[1]+t*t*p2[1]))
    while i<len(cmds):
        c=cmds[i];i+=1
        if c=='M':
            if cur:contours.append(cur)
            x,y=cmds[i],cmds[i+1];i+=2;sx,sy=x,y;cur=[(x,y)]
        elif c=='L':x,y=cmds[i],cmds[i+1];i+=2;cur.append((x,y))
        elif c=='H':x=cmds[i];i+=1;cur.append((x,y))
        elif c=='V':y=cmds[i];i+=1;cur.append((x,y))
        elif c=='C':
            p1=(cmds[i],cmds[i+1]);p2=(cmds[i+2],cmds[i+3]);p3=(cmds[i+4],cmds[i+5]);i+=6
            cur+=cub((x,y),p1,p2,p3)[1:];x,y=p3
        elif c=='Q':
            p1=(cmds[i],cmds[i+1]);p2=(cmds[i+2],cmds[i+3]);i+=4
            cur+=quad((x,y),p1,p2)[1:];x,y=p2
        elif c=='Z':
            if cur:contours.append(cur);cur=[]
            x,y=sx,sy
    if cur:contours.append(cur)
    return [[(float(a),float(b)) for a,b in c] for c in contours]

def bbox(cs):
    p=np.array([q for c in cs for q in c]);return p[:,0].min(),p[:,1].min(),p[:,0].max(),p[:,1].max()

def pen_to_contours(pen):
    contours=[];cur=[];start=None;last=None
    def cub(p0,p1,p2,p3,n=40):
        t=np.linspace(0,1,n)
        return list(zip((1-t)**3*p0[0]+3*(1-t)**2*t*p1[0]+3*(1-t)*t*t*p2[0]+t**3*p3[0],
                        (1-t)**3*p0[1]+3*(1-t)**2*t*p1[1]+3*(1-t)*t*t*p2[1]+t**3*p3[1]))
    def quad(p0,p1,p2,n=28):
        t=np.linspace(0,1,n)
        return list(zip((1-t)**2*p0[0]+2*(1-t)*t*p1[0]+t*t*p2[0],
                        (1-t)**2*p0[1]+2*(1-t)*t*p1[1]+t*t*p2[1]))
    for op,args in pen.value:
        if op=='moveTo':
            if cur:contours.append(cur)
            last=args[0];start=last;cur=[last]
        elif op=='lineTo':last=args[0];cur.append(last)
        elif op=='curveTo':
            pts=list(args)
            if len(pts)==3:cur+=cub(last,pts[0],pts[1],pts[2])[1:];last=pts[2]
            else:
                for p in pts:cur.append(p);last=p
        elif op=='qCurveTo':
            pts=list(args);p0=last
            if pts[-1] is None:pts[-1]=start
            offs=pts[:-1];end=pts[-1];prev=p0
            for j,off in enumerate(offs):
                mid=end if j==len(offs)-1 else ((off[0]+offs[j+1][0])/2,(off[1]+offs[j+1][1])/2)
                cur+=quad(prev,off,mid)[1:];prev=mid
            last=end
        elif op=='closePath':
            if cur:contours.append(cur);cur=[]
            last=start
    if cur:contours.append(cur)
    return contours

def normalize(cs,flip_y=False,cap=CAP):
    x0,y0,x1,y1=bbox(cs);h=y1-y0;s=cap/h
    return [[((x-x0)*s,((y1-y) if flip_y else (y-y0))*s) for x,y in c] for c in cs]

# ---------- Marken-Glyphen ----------
MOBILE={
 "T":"M538 542H380V0H174V542H5V696H559Z",
 "W":"M758 0H490L428 500L359 0H97L0 696H206L250 149L324 696H539L601 149L661 696H859Z",
 "E":"M479 553H249V423H451V284H249V144H495V0H43V696H500Z",
 "N":"M608 0H350L198 508L201 486Q212 413 218.5 352.5Q225 292 225 216V0H43V696H296L454 186L451 207Q439 276 432.5 335.5Q426 395 426 473V696H608Z",
 "Y":"M413 257V0H207V256L-15 696H210L313 412L417 696H635Z",
 "O":"M670 349Q670 236 632 153Q594 70 521 25Q448 -20 345 -20Q189 -20 104.5 77.5Q20 175 20 349Q20 462 58 544.5Q96 627 169 671.5Q242 716 345 716Q501 716 585.5 619.5Q670 523 670 349ZM234 349Q234 229 260 177.5Q286 126 345 126Q405 126 430.5 177Q456 228 456 349Q456 469 430 519.5Q404 570 345 570Q286 570 260 519.5Q234 469 234 349Z",
}
def load_portal():
    big=max(re.findall(r'<path\s+d="([^"]+)"',open(PORTAL_SVG).read()),key=len)
    subs=[s.strip()+"Z" for s in big.split("Z") if s.strip()]
    items=[(parse_svg(s),) for s in subs]
    items=[(cs,bbox(cs)) for (cs,) in items]
    used=[False]*len(items)
    order=sorted(range(len(items)),key=lambda k:-((items[k][1][2]-items[k][1][0])*(items[k][1][3]-items[k][1][1])))
    groups=[]
    for k in order:
        if used[k]:continue
        cs,bb=items[k];used[k]=True;g=list(cs)
        for j in order:
            if used[j]:continue
            b2=items[j][1]
            if b2[0]>=bb[0]-2 and b2[1]>=bb[1]-2 and b2[2]<=bb[2]+2 and b2[3]<=bb[3]+2:
                g+=items[j][0];used[j]=True
        groups.append((g,bbox(g)))
    rows={}
    for g,bb in groups:rows.setdefault(round((bb[1]+bb[3])/2/150),[]).append((g,bb))
    ordered=[gb for yc in sorted(rows) for gb in sorted(rows[yc],key=lambda t:t[1][0])]
    out={}
    for lab,(g,bb) in zip("EINUNDZWANZIG",ordered):out.setdefault(lab,g)
    return out

LIB={k:normalize(parse_svg(v)) for k,v in MOBILE.items()}
_p=load_portal()
for k in "IUDZAG":LIB[k]=normalize(_p[k],flip_y=True)

# ---------- Metriken + Konstruktionen ----------
def gw(cs):b=bbox(cs);return b[2]-b[0]
STEM=gw(LIB["I"]);BARH=143.0
def rect(x0,y0,x1,y1):return [(x0,y0),(x1,y0),(x1,y1),(x0,y1)]
def make_H(W=560):return [rect(0,0,STEM,CAP),rect(W-STEM,0,W,CAP),rect(STEM,(CAP-BARH)/2,W-STEM,(CAP+BARH)/2)]
def make_V(W=610,sw=212):return [[(0,CAP),(sw,CAP),(W/2,205),(W-sw,CAP),(W,CAP),(W/2,0)]]
def make_M(W=760,sw=None,pw=305,vY=250,th=205):
    sw=sw or STEM
    return [rect(0,0,sw,CAP),rect(W-sw,0,W,CAP),
            [(sw,CAP),(W/2,vY),(W-sw,CAP),(W-pw,CAP),(W/2,vY+th),(pw,CAP)]]
def make_C(gap_deg=40,n=70):
    o=LIB["O"];ob=bbox([o[0]]);ib=bbox([o[1]])
    cx=(ob[0]+ob[2])/2;cy=(ob[1]+ob[3])/2
    Rxo=(ob[2]-ob[0])/2;Ryo=(ob[3]-ob[1])/2;Rxi=(ib[2]-ib[0])/2;Ryi=(ib[3]-ib[1])/2
    g=np.radians(gap_deg);a=np.linspace(g,2*np.pi-g,n)
    outer=[(cx+Rxo*np.cos(t),cy+Ryo*np.sin(t)) for t in a]
    inner=[(cx+Rxi*np.cos(t),cy+Ryi*np.sin(t)) for t in a[::-1]]
    return [outer+inner]
def font_glyph(path,ch):
    ft=TTFont(path)
    if "fvar" in ft:
        ax={a.axisTag:a for a in ft["fvar"].axes};inst={}
        if "wght" in ax:inst["wght"]=ax["wght"].maxValue
        ft=instancer.instantiateVariableFont(ft,inst,inplace=False)
    cmap=ft.getBestCmap();gs=ft.getGlyphSet()
    ph=RecordingPen();gs[cmap[ord('H')]].draw(ph);hb=bbox(pen_to_contours(ph));cap=hb[3]-hb[1];base=hb[1]
    pen=RecordingPen();gs[cmap[ord(ch)]].draw(pen);cs=pen_to_contours(pen)
    s=CAP/cap;x0=bbox(cs)[0]
    return [[((x-x0)*s,(y-base)*s) for x,y in c] for c in cs]
def make_acute(W):
    x0=W*0.32;x1=W*0.60;y0=CAP+60;y1=CAP+210;t=72
    return [[(x0,y0),(x0+t,y0),(x1+t,y1),(x1,y1)]]

LEAGUE_SPARTAN=os.path.join(FONTS,"LeagueSpartan-Variable.ttf")
LIB["H"]=make_H();LIB["V"]=make_V();LIB["M"]=make_M();LIB["C"]=make_C()
LIB["S"]=font_glyph(LEAGUE_SPARTAN,"S")
LIB["J"]=font_glyph(LEAGUE_SPARTAN,"J")
def with_accent(base_cs,W):return [list(c) for c in base_cs]+make_acute(W)
LIB["Ś"]=with_accent(LIB["S"],gw(LIB["S"]))

# ---------- Komposition ----------
TRACK=70.0;SPACE=300.0;PAD=30.0
def shift(cs,dx,dy=0):return [[(x+dx,y+dy) for x,y in c] for c in cs]
def compose(text):
    placed=[];adv=PAD
    for ch in text:
        if ch==" ":adv+=SPACE;continue
        g=LIB[ch];w=gw(g)
        placed+=shift(g,adv);adv+=w+TRACK
    adv=adv-TRACK+PAD
    return placed,adv

def to_svg(contours):
    pts=np.array([q for c in contours for q in c])
    miny=pts[:,1].min();maxy=pts[:,1].max();maxx=pts[:,0].max()
    H=maxy-miny;W=maxx+PAD
    def fmt(v):return f"{v:.1f}".rstrip('0').rstrip('.')
    d=""
    for c in contours:
        d+="M"+" ".join(f"{fmt(x)} {fmt(maxy-y)}" for x,y in c)+"Z"
    vb=f"0 {fmt(0)} {fmt(W)} {fmt(H)}"
    return (f'<svg viewBox="{vb}" {{{{ $attributes }}}} role="img" '
            f'xmlns="http://www.w3.org/2000/svg">\n'
            f'<path fill-rule="evenodd" fill="currentColor" d="{d}"/>\n</svg>\n')

WORDMARKS={
 "twenty-one":"TWENTY ONE",
 "einundzwanzig":"EINUNDZWANZIG",
 "veintiuno":"VEINTIUNO",
 "huszonegy":"HUSZONEGY",
 "eenentwintig":"EENENTWINTIG",
 "dwadziescia-jeden":"DWADZIEŚCIA JEDEN",
 "vinte-e-um":"VINTE E UM",
}

if __name__=="__main__":
    proof="--proof" in sys.argv
    os.makedirs(OUT,exist_ok=True)
    rendered={}
    for slug,text in WORDMARKS.items():
        contours,_=compose(text)
        svg=to_svg(contours)
        open(os.path.join(OUT,f"{slug}.blade.php"),"w").write(svg)
        rendered[slug]=(text,contours)
        print(f"wrote {slug}.blade.php  ({text})")
    if proof:
        from PIL import Image,ImageDraw,ImageChops
        rows=[]
        for slug,(text,contours) in rendered.items():
            pts=np.array([q for c in contours for q in c])
            minx,miny,maxx,maxy=pts[:,0].min(),pts[:,1].min(),pts[:,0].max(),pts[:,1].max()
            H=140;pad=12;s=(H-2*pad)/(maxy-miny);Wp=int((maxx-minx)*s+2*pad)
            acc=Image.new("1",(max(Wp,1),H),0)
            for c in contours:
                im=Image.new("1",(max(Wp,1),H),0)
                ImageDraw.Draw(im).polygon([((x-minx)*s+pad,H-pad-(y-miny)*s) for x,y in c],fill=1)
                acc=ImageChops.logical_xor(acc,im)
            rgb=Image.new("RGB",acc.size,(255,255,255));rgb.paste(Image.new("RGB",acc.size,(15,15,15)),(0,0),acc)
            rows.append((slug,rgb))
        maxW=max(r[1].width for r in rows);H=140;gap=10
        sheet=Image.new("RGB",(maxW+30,(H+gap)*len(rows)+10),(255,255,255));dr=ImageDraw.Draw(sheet)
        for i,(slug,im) in enumerate(rows):sheet.paste(im,(20,i*(H+gap)+5))
        sheet.save("/tmp/brandfonts/wordmarks.png");print("proof: /tmp/brandfonts/wordmarks.png",sheet.size)
