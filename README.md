# स्टोर कोड → cPanel ऑटो-डिप्लॉय

इस repo में तुम्हारे WordPress स्टोर का कस्टम कोड है। `main` branch पर push करते ही GitHub Actions उसे अपने आप तुम्हारे cPanel पर डाल देता है।

## क्या अपने आप जाता है

| Repo में | सर्वर पर |
|---|---|
| `wp-content/themes/store-child/` | `wp-content/themes/store-child/` |
| `wp-content/plugins/store-features/` | `wp-content/plugins/store-features/` |

हर deploy में ये चार काम होते हैं:

1. **Secrets की जाँच:** FTP की कोई जानकारी छूटी हो तो साफ़ error मिलता है।
2. **PHP syntax जाँच:** कोड में गलती हो तो deploy वहीं रुक जाता है, live साइट नहीं टूटती।
3. **Upload:** FTPS (encrypted) से, और सिर्फ़ बदली हुई फ़ाइलें।
4. **पहचान:** WordPress admin के नीचे footer में दिखता है कि कौन सा commit live है।

## क्या इस pipeline से नहीं जाता

- WordPress, Kadence थीम, WooCommerce और बाकी प्लगइन। इन्हें WordPress admin से install और update करो।
- प्रोडक्ट, पेज, सेटिंग्स और ऑर्डर। ये database में रहते हैं, कोड में नहीं।
- इमेज और uploads।

## एक बार का सेटअप

### 1. WordPress तैयार करो

- cPanel के WordPress installer से WordPress install करो।
- **Appearance → Themes → Add New** से **Kadence** install करो। उसे activate करने की ज़रूरत नहीं, हमारी child theme उसी पर चलती है।

### 2. Deploy के लिए अलग FTP account बनाओ

cPanel → **FTP Accounts** → नया account:

- **Directory:** `public_html/wp-content` (WordPress किसी सब-फ़ोल्डर में है तो उसी का `wp-content`)
- **Password:** लंबा और मज़बूत

ये account सिर्फ़ `wp-content` के अंदर काम कर सकता है। पासवर्ड कभी लीक भी हो जाए, तो बाकी साइट और cPanel सुरक्षित रहते हैं।

बनने के बाद उसी account के आगे **Configure FTP Client** खोलो। वहाँ FTP server का नाम और port लिखा होता है।

> अलग account नहीं बना सकते? मुख्य FTP account भी चलेगा। तब step 4 में `FTP_BASE_DIR` secret में `public_html/wp-content/` डालना (आख़िर में `/` ज़रूरी है)।

### 3. GitHub पर खाली private repo बनाओ

GitHub → **New repository** → **Private** चुनो। README या .gitignore मत जोड़ना, repo खाली रहना चाहिए।

### 4. Secrets डालो (push से पहले)

Repo → **Settings → Secrets and variables → Actions → New repository secret**

| Secret | क्या डालना है |
|---|---|
| `FTP_SERVER` | FTP server का नाम (step 2 में दिखा) |
| `FTP_USERNAME` | पूरा username, जैसे `deploy@tumhara-domain.com` |
| `FTP_PASSWORD` | उस account का पासवर्ड |

ये वैकल्पिक हैं, ज़रूरत पड़े तभी डालना:

| Secret | कब डालना है |
|---|---|
| `FTP_PORT` | जब port 21 न हो |
| `FTP_BASE_DIR` | जब मुख्य FTP account इस्तेमाल कर रहे हो, जैसे `public_html/wp-content/` |
| `FTP_SECURITY` | `strict` डालो, अगर server का SSL certificate उसी नाम पर है जो `FTP_SERVER` में डाला। ये ज़्यादा सुरक्षित है। |

### 5. Code push करो

इस फ़ोल्डर में terminal खोलकर:

```bash
git init -b main
git add .
git commit -m "Store code and deploy pipeline"
git remote add origin https://github.com/<username>/<repo>.git
git push -u origin main
```

### 6. पहली बार जाँचो

1. GitHub पर **Actions** टैब खोलो। 1 से 2 मिनट में हरा ✓ दिखना चाहिए।
2. WordPress admin में **Appearance → Themes → Store Child** activate करो।
3. **Plugins → Store Features** activate करो।
4. Admin के सबसे नीचे footer में `deploy abc1234` जैसा कुछ दिखे, तो pipeline चालू है।

> बिना कुछ upload किए सिर्फ़ connection परखना हो, तो **Actions → Deploy to cPanel → Run workflow** खोलो और **Dry run** पर टिक लगाओ।

## रोज़ का तरीका

कोड बदलो → `git commit` → `git push` → 1 से 2 मिनट में live।

## गड़बड़ हो जाए तो

```bash
git revert HEAD
git push
```

पिछला कोड फिर से deploy हो जाएगा।

## आम दिक्कतें

| Error | हल |
|---|---|
| `Missing GitHub secrets` | Step 4 के secrets ठीक इन्हीं नामों से डालो |
| Login failed या 530 | पूरा username डालो (`@domain` के साथ) और पासवर्ड दोबारा जाँचो |
| Certificate या TLS error | `FTP_SECURITY` secret हटा दो, या `FTP_SERVER` में वही server नाम डालो जो cPanel में दिखा |
| Timeout | होस्ट बाहर से आने वाले FTP connection रोक रहा हो सकता है। होस्ट सपोर्ट से पूछो, या SSH वाला तरीका अपनाओ |
| `PHP Parse error` | Log में फ़ाइल और लाइन नंबर दिखेगा। ठीक करके दोबारा push करो |

## ज़रूरी नियम

- पासवर्ड कभी repo में मत लिखो, सिर्फ़ GitHub Secrets में रखो।
- `store-child` और `store-features` की फ़ाइलें सर्वर पर सीधे मत बदलो, अगला deploy उन्हें बदल देगा। हर बदलाव repo से करो।
- सर्वर पर `.ftp-deploy-sync-state.json` फ़ाइल बनेगी। इसी से pipeline जानती है कि कौन सी फ़ाइल बदली, इसलिए इसे मत हटाना।
- GitHub Free में private repo के लिए हर महीने 2,000 मिनट मुफ़्त मिलते हैं, और एक deploy में 1 से 2 मिनट लगते हैं।
