# Eklentiler

Her eklenti `addons/{Yazar}/{Ad}/` klasöründe durur ve yönetim panelindeki **Eklentiler** sayfasından
açılıp kapatılır. Kapalı eklentinin hiçbir kodu çalışmaz.

```
addons/Meydan/Example/
├── addon.json        kimlik, sürüm, ad alanı
├── src/Addon.php     ana sınıf (App\Forum\Addons\Addon sınıfını genişletir)
├── routes.php        isteğe bağlı: rotalar ("web" ara katmanı içinde yüklenir)
├── views/            isteğe bağlı: view('meydan-example::ad')
├── lang/tr.json      isteğe bağlı: çeviriler
└── migrations/       isteğe bağlı: eklenti açılırken çalıştırılır
```

## addon.json

```json
{
    "id": "Meydan/Example",
    "title": "Example add-on",
    "description": "…",
    "version": "1.0.0",
    "author": "Meydan",
    "namespace": "Meydan\\Example",
    "main": "Addon"
}
```

`id` klasör yoluyla aynı olmalıdır (`Yazar/Ad`, yalnızca harf ve rakam). `namespace` altındaki sınıflar
`src/` klasöründen PSR-4 kuralıyla yüklenir.

## Ana sınıf

```php
namespace Meydan\Example;

use App\Forum\Addons\Addon as BaseAddon;

class Addon extends BaseAddon
{
    public function boot(): void
    {
        $this->loadViews();
        $this->loadTranslations();
        $this->loadRoutes();

        $this->hook('layout.footer', fn (array $context) => view('meydan-example::footer'));
        $this->on('forum.thread.created', fn ($thread) => /* ... */ null);
    }

    public function install(): void {}     // eklenti açılırken bir kez
    public function uninstall(): void {}   // eklenti kapatılırken
}
```

## Kancalar (hook)

Şablonlardaki `@hook('ad')` satırları, o ada bağlanan eklentilerin çıktısını basar. Dönen değer HTML olarak
yazılır; kullanıcıdan gelen veriyi eklenti kendisi kaçırmalıdır (Blade görünümleri bunu zaten yapar).

| Kanca | Yer | `$context` |
| --- | --- | --- |
| `layout.head` | `<head>` sonu | — |
| `layout.body_start` | `<body>` başı | — |
| `layout.nav` | ana menünün sonu | — |
| `layout.user_menu` | üye menüsü | `user` |
| `layout.footer` | alt bilgi bağlantıları | — |
| `index.sidebar` | ana sayfa sağ sütunu | — |
| `thread.after_first_post` | konuda ilk mesajın altı | `thread` |
| `post.footer` | her mesajın düğme satırı | `post`, `thread` |
| `profile.sidebar` | üye profili sağ sütunu | `member` |
| `admin.dashboard` | yönetim paneli ana sayfası | — |
| `ads.empty` | satılmamış reklam alanı (dönen HTML, "buraya reklam verin" kutusunun yerine geçer) | `position` |

Değer süzen kancalar (`$this->hook('ad', fn ($deger, ...$ek) => $yeniDeger)`):

| Kanca | Ne süzülür | Ek parametre |
| --- | --- | --- |
| `bbcode.render` | mesaj HTML'e çevrilirken ara çıktı (kendi BBCode etiketleriniz için) | `$hold` |
| `bbcode.plain` | düz metne çevrilmeden önce mesaj kaynağı (özet, arama, bildirim) | — |
| `post.html` | bir mesaj gösterilirken HTML'i | `$post` |
| `post.source` | alıntı ve API için mesaj kaynağı | `$post` |
| `robots.lines` | `robots.txt` satırları (dizi) | — |

## Ayarlar

`addon.json` içine `settings` eklerseniz yönetim panelinde **Eklentiler > Ayarlar** sayfası kendiliğinden oluşur:

```json
"settings": {
    "api_key": { "label": "API key", "type": "secret", "default": "" },
    "enabled": { "label": "Announce new threads", "type": "bool", "default": true, "hint": "…" }
}
```

Türler: `string`, `text`, `int` (`min`, `max`), `bool`, `select` (`options`), `secret`. Etiketler eklentinin `lang/tr.json`
dosyasıyla çevrilir. Değer `$this->setting('api_key')` ile okunur.

## Olaylar

`$this->on('olay', fn ($model) => …)` ile dinlenir:

- `forum.thread.created` — yeni (görünür) konu, parametre `App\Models\Thread`
- `forum.post.created` — yeni (görünür) cevap, parametre `App\Models\Post`
- `forum.user.registered` — yeni üye, parametre `App\Models\User`

## Güvenli mod

Bir eklenti ya da şablon değişikliği sayfaları bozarsa `.env` dosyasına `FORUM_SAFE_MODE=true` yazın:
eklentiler, şablon değişiklikleri, özel CSS ve özel HTML devre dışı kalır; sorunu giderip satırı silin.
