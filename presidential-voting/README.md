# Presidential Voting (XAMPP)

Plain PHP + MySQL app — no Composer needed.

1. Copy this folder to `C:\xampp\htdocs\presidential-voting`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open <http://localhost/presidential-voting/setup.php> (creates the database, demo candidates and voters), then **delete `setup.php`**.
4. Open <http://localhost/presidential-voting/>.

| Role | Login |
|---|---|
| Admin (`/admin/login.php`) | `admin` / `Admin@12345` — change in `includes/config.php` before running setup |
| Demo voters | `V1001`/`1111`, `V1002`/`2222`, `V1003`/`3333` |

Features: PIN-protected voters, one vote each (atomic, transaction-safe), anonymous ballots with receipt code, live results, admin panel for candidates, voter roll and open/close/schedule. DB settings are in `includes/config.php`.
