# Omnoi Academy — ระบบ LMS ภายในองค์กร

ระบบเรียนรู้ออนไลน์ภายในของ Omnoi Group สำหรับพัฒนาทักษะพนักงานแยกตามสายงาน (ขาย / อะไหล่ / ช่าง / อื่นๆ)

- **เว็บแอป:** หน้าเดียว HTML/CSS/JS ล้วน (ไม่มี build step) — เปิด `index.html` ได้เลย
- **โฮสต์เว็บ:** HostNeverDie ที่ `lms.omnoigroup-it.com`
- **วิดีโอ:** Cloudflare Stream (เก็บและสตรีมแยกจากเซิร์ฟเวอร์)
- **เวอร์ชัน:** จัดการด้วย Git / GitHub

> สถานะปัจจุบัน: **MVP ฝั่งหน้าเว็บ (frontend-only)** — ข้อมูลผู้ใช้/คอร์ส/คะแนนเก็บใน `localStorage` ของเบราว์เซอร์ ยังไม่มี backend กลาง เหมาะสำหรับสาธิตและนำร่อง ดูหัวข้อ "แผนเฟสถัดไป" สำหรับการทำใช้จริงหลายคน

---

## คุณสมบัติ

1. **เข้าสู่ระบบ** ด้วย username + password
2. **คอร์สแยกตาม track** ของพนักงาน — แต่ละคนเห็นเฉพาะหลักสูตรสายงานตัวเอง
3. **บังคับดูวิดีโอให้จบ ห้ามกรอ/ห้ามข้าม** — ปิดแถบควบคุมของ player และบล็อกการกระโดดเวลาไปข้างหน้า + หยุดอัตโนมัติเมื่อสลับแท็บ และปลดล็อกบทถัดไปทีละบท
4. **แบบทดสอบท้ายคอร์ส + บันทึกคะแนน** รายบุคคล (เกณฑ์ผ่าน 80% เก็บคะแนนสูงสุด/จำนวนครั้ง)
5. **ฝั่งแอดมิน** — สร้างคอร์ส ผูก Cloudflare Video UID และดูรายงานคะแนนพนักงานรายบุคคล (กรองตามสายงาน)

## บัญชีทดลอง

| Username | Password | บทบาท |
|----------|----------|-------|
| `somchai` | `1234` | พนักงานขาย |
| `nattaya` | `1234` | อะไหล่ |
| `weera` | `1234` | ช่าง |
| `ploy` | `1234` | อื่นๆ |
| `admin` | `admin` | ผู้ดูแลระบบ |

> ⚠️ บัญชีและรหัสผ่านเหล่านี้เป็นข้อมูลตัวอย่างในไฟล์ `index.html` (ตัวแปร `USERS`) — **ต้องเปลี่ยนก่อนใช้งานจริง** และไม่ควรเก็บรหัสผ่านจริงในโค้ดฝั่ง client (ดู "แผนเฟสถัดไป")

---

## โครงสร้างไฟล์

```
webapp/
├── index.html      # ทั้งแอป (UI + ตรรกะ + ข้อมูลตัวอย่าง)
├── logo.png        # โลโก้ Omnoi (Trusty Boy)
├── README.md
└── .gitignore
```

## รันในเครื่อง (local)

เปิดไฟล์ `index.html` ด้วยเบราว์เซอร์ได้เลย หรือเสิร์ฟผ่าน static server:

```bash
# ตัวอย่างด้วย Python
python3 -m http.server 8080
# แล้วเปิด http://localhost:8080
```

---

## เชื่อมต่อ Cloudflare Stream

1. สร้างบัญชี Cloudflare และเปิดใช้ **Stream**
2. อัปโหลดวิดีโอบทเรียนใน Dashboard → Stream
3. คัดลอก **Video UID** ของแต่ละคลิป (อยู่ในหน้ารายละเอียดวิดีโอ)
4. ในเว็บ เข้าสู่ระบบเป็น `admin` → เมนู **จัดการคอร์ส**
   - (ไม่บังคับ) กรอก **Customer Subdomain code** ในกล่อง "ตั้งค่า Cloudflare Stream" — ดูจาก URL `customer-XXXX.cloudflarestream.com`
   - สร้างคอร์ส แล้ววาง **Video UID** ในช่องของแต่ละบท
5. บทที่ใส่ UID จะเล่นผ่าน Cloudflare Stream จริงพร้อมระบบห้ามกรอ ส่วนบทที่เว้นว่างจะเล่นเป็น **โหมดสาธิต**

> การห้ามกรอทำงานฝั่งเบราว์เซอร์ (ปิด controls + บล็อก seek) เพียงพอสำหรับ MVP — เพื่อความรัดกุมระดับ production ควรเพิ่มการตรวจสอบฝั่งเซิร์ฟเวอร์ (heartbeat) และ Signed URL (ดูเฟสถัดไป)

---

## Deploy ขึ้น HostNeverDie (`lms.omnoigroup-it.com`)

1. ใน DirectAdmin สร้าง **subdomain** `lms.omnoigroup-it.com`
2. อัปโหลดไฟล์ในโฟลเดอร์นี้ (`index.html`, `logo.png`) เข้าไปยัง document root ของ subdomain (เช่น `domains/omnoigroup-it.com/public_html/lms/`) ผ่าน File Manager หรือ FTP
3. เปิดใช้ **SSL ฟรี (Let's Encrypt)** ให้ subdomain
4. ทดสอบเปิด `https://lms.omnoigroup-it.com`

> เว็บเป็น static ล้วน จึงรองรับได้ทั้ง Shared Hosting และ VPS ของ HostNeverDie

---

## จัดการเวอร์ชันด้วย GitHub

repo นี้พร้อม push แล้ว (มี commit แรกให้):

```bash
# สร้าง repo เปล่าใน GitHub ก่อน (เช่น omnoi-lms) แล้ว:
git remote add origin https://github.com/<org-หรือ-user>/omnoi-lms.git
git branch -M main
git push -u origin main
```

ครั้งต่อไปเมื่อแก้ไข:

```bash
git add -A
git commit -m "อธิบายการเปลี่ยนแปลง"
git push
```

---

## Auto-deploy: GitHub → HostNeverDie (FTP)

repo นี้มี GitHub Actions (`.github/workflows/deploy.yml`) ที่จะ **อัปไฟล์ขึ้น HostNeverDie ผ่าน FTP ให้อัตโนมัติทุกครั้งที่ push ขึ้น branch `main`** (และกดรันเองได้ที่แท็บ Actions)

ตั้งค่าครั้งเดียว — เพิ่ม **Secrets** ใน GitHub repo:
`Settings → Secrets and variables → Actions → New repository secret`

| ชื่อ Secret | ค่า |
|-------------|-----|
| `FTP_SERVER` | `ftp.omnoigroup-it.com` (หรือ IP เซิร์ฟเวอร์) |
| `FTP_USERNAME` | ชื่อบัญชี FTP |
| `FTP_PASSWORD` | รหัสผ่าน FTP |

จากนั้นทุก `git push` → ดูสถานะการ deploy ได้ที่แท็บ **Actions**

หมายเหตุ:
- ปรับ `server-dir` ในไฟล์ workflow ให้ตรงกับโฟลเดอร์ปลายทาง — ถ้าบัญชี FTP จำกัดอยู่ในโฟลเดอร์ subdomain แล้วใช้ `./` ได้เลย ถ้าเป็นบัญชีหลักให้ใส่ path เต็ม เช่น `/domains/omnoigroup-it.com/public_html/lms/`
- ถ้า `ftps` ต่อไม่ติด ให้เปลี่ยน `protocol:` เป็น `ftp`
- การ deploy ครั้งแรก action จะสร้างไฟล์ `.ftp-deploy-sync-state.json` บนเซิร์ฟเวอร์เพื่อจำว่าไฟล์ไหนเปลี่ยน (ครั้งถัดไปอัปเฉพาะที่แก้ ทำให้เร็วขึ้น)

GitHub ใช้สำหรับ **เก็บประวัติเวอร์ชัน + auto-deploy** ส่วนวิดีโออยู่บน Cloudflare Stream

---

## แผนเฟสถัดไป (เพื่อใช้งานจริงหลายคน)

ปัจจุบันข้อมูลอยู่ในเบราว์เซอร์ของแต่ละเครื่อง (คะแนนไม่รวมศูนย์ข้ามอุปกรณ์) เมื่อพร้อมขยาย แนะนำเพิ่ม **Cloudflare Worker + D1 (ฐานข้อมูล)** เพื่อ:

- ระบบล็อกอินจริงและจัดการผู้ใช้กลาง (ไม่เก็บรหัสผ่านในโค้ด client)
- บันทึกคะแนน/ความคืบหน้ารวมศูนย์ ดูรายงานข้ามอุปกรณ์ได้
- ออก **Signed URL** ของ Cloudflare Stream (ลิงก์หมดอายุ กันวิดีโอหลุด)
- ตรวจสอบการดูจบฝั่งเซิร์ฟเวอร์ (heartbeat) ป้องกันการข้ามวิดีโอแบบแกะระบบ

---

© Omnoi Group · Internal Learning Platform 2026
