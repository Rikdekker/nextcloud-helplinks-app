# How Help Links works

This page describes what the app shows, where it stores things, and why the
IntroVox tour list works the way it does. For building a release, see
[BuildApp.md](BuildApp.md).

## The Help page

Users open **Help** from the app menu (`/apps/helplinks/`). The page loads
everything in one request, `GET /apps/helplinks/api/sections`, and shows these
cards:

| Card | Shown when |
|---|---|
| Introduction tour | IntroVox is enabled for this user |
| Help sections | the admin configured them; a section needs a main link URL, empty sub-links are left out |
| Talk app | Talk (`spreed`) is enabled for this user |
| Files app | always |
| Your Cloud ID | the user has a Federated Cloud ID |
| WebDAV access | always |
| Support | a support e-mail address or support URL is set |

When the first help section exists and IntroVox is enabled, the two share the top
row.

## Configuration

Admins configure the app under **Administration settings > Additional settings**.

- **Help sections**: a title, description, main link and any number of
  sub-links, in an order you choose.
- **IT support settings**: environment name, support e-mail address and support
  portal URL.

The support settings can also be set from the command line:

```sh
occ config:app:set helplinks support_email --value=servicedesk@example.com
occ config:app:set helplinks support_url --value=https://servicedesk.example.com
occ config:app:set helplinks environment_name --value="Research Drive"
```

## Introduction tour: the user's IntroVox tours

If [IntroVox](https://apps.nextcloud.com/apps/introvox) is enabled for the user,
the Introduction tour card lists the tours that this user may get. Each item
starts its tour. Below the list, **Tour settings** opens IntroVox's personal
settings, which show which tours the user has seen and let them start a tour
again.

### Which tours are listed

These are the same tours IntroVox lists in its personal settings, plus the welcome
tour at the top. IntroVox decides who may get a tour:

- the tour is switched on, and so is the tours main switch;
- the tour has no groups, or the user is in one of them;
- for an app tour, the app is enabled for the user.

Whether the user has already seen a tour does not matter for the list.

Help Links reads this from IntroVox's `TourRepository`; IntroVox needs no
changes for it. That class is not a public API, so every call is guarded
(`class_exists`, `isEnabledForUser('introvox')` and a catch-all). If anything
fails, the card shows the original single **Go to the tour** button, and the
error is logged as a warning.

A tour link points to the tour's own page with `?introvox-tour=<id>`, for
example `/apps/spreed/?introvox-tour=builtin-talk`. IntroVox picks the tour up
there and checks again whether the user may get it before it shows a step.
IntroVox's share link `/apps/introvox/tour/<id>` is not used: in IntroVox 2.0.0
it redirects to `//apps/...` on instances with pretty URLs.

### When the list is refreshed

The list is stored per user, and rebuilt only when that user opens the Help
page and one of these is true:

1. **No list is stored yet**, or the stored list is **older than 24 hours**.
2. **Something it depends on has changed.** With every list, Help Links stores a
   hash of:
   - IntroVox's tour configuration (app config `introvox` / `tours`);
   - the tours main switch;
   - the user's language;
   - the user's groups;
   - the apps enabled for the user.

   A different hash means the list is rebuilt at once. An admin who adds,
   changes or restricts a tour does not have to wait a day.

Otherwise the stored list is used as it is. Everything IntroVox uses to decide
who gets a tour is in the hash, so the 24-hour limit only catches what is not:
cosmetic changes such as an app icon, or the translated name of a built-in tour
after an IntroVox update.

A Help page that is already open does not update itself; reloading it is enough.
There is no button to fetch the list again: a reload does the same, because any
change that matters is already in the hash.

An admin cannot push a tour to a user from Help Links; its settings have no
tours. To get a tour to people, the admin uses IntroVox: switch the tour on,
limit it to groups, or use **Show again**. Help Links follows on the user's next
visit.

### Why there is no daily background job

A background job that fetches the tours of every user once a day was considered
and left out, for three reasons:

- **It does work nobody looks at.** A job would build a list for every account,
  every day. On a large instance most users never open the Help page, so almost
  all of that work would be thrown away. The lazy approach builds a list only for
  someone who opens Help, at most once a day, and never for anyone else.
- **It would not make the list fresher.** A user who opens Help gets a list that
  is at most a day old either way. Changes to the tours show up at once anyway,
  through the hash. A job would only add a list nobody reads yet.
- **Its cost grows with the number of accounts, not with use.** A job's run time
  and database writes grow with every account added. The lazy approach costs
  nothing for people who don't use the page.

Building a list is cheap in itself:

- IntroVox's tour configuration is one preloaded app config value.
- Group and app checks use Nextcloud's per-request caches.
- Writing the list is one user-preference write.

So rebuilding during a page view is not noticeable. See
[Large instances](#large-instances) for measurements and what this means at
10,000 or 100,000 users.

### What happens when a tour is added or taken away

Each change below shows up the next time the user opens Help, because it changes
the hash. This was tested on Nextcloud 35 with IntroVox 2.0.0, comparing Help
Links' list with IntroVox's own list after every step:

| Change | Effect on the user's list |
|---|---|
| Admin adds a tour for everyone | appears |
| Admin limits a tour to a group the user is not in | disappears |
| User is added to that group | appears again |
| User is removed from that group | disappears again |
| The tour's app is no longer enabled for the user | its tour disappears |
| Admin switches the IntroVox main switch off | list is empty (no welcome tour either) |
| Admin switches it on again | list is back |

A user who keeps the Help page open while a tour is taken away can still click
it. IntroVox then refuses the steps (HTTP 404), and the tour does not start;
the user stays on the tour's page without an error.

### Where it is stored

The list is stored as a **user preference** in `oc_preferences`: app
`helplinks`, key `introvox_tours`, one per user. It needs no table of its own
and no migration. The value is JSON:

```json
{
  "hash": "sha1 of what the list depends on (see above)",
  "fetchedAt": 1793023753,
  "tours": [
    {"id": "builtin-talk", "name": "Talk", "page": "/apps/spreed/",
     "app": {"name": "Talk", "icon": "/custom_apps/spreed/img/app.svg"}}
  ]
}
```

- **Lazy loading:** from Nextcloud 32 the preference is stored through
  `IUserConfig` as a *lazy* value. Nextcloud only loads it when the Help page
  asks for it, not with every request of that user. On Nextcloud 30 and 31 it is
  a plain user value (`IConfig::setUserValue`).
- **Per visit:** one preference read while the stored list is current, plus one
  write when it is rebuilt.
- **Users without IntroVox** never get the preference.
- **Deleted users:** Nextcloud removes all of an account's preferences when it
  deletes the account (`User::delete()`). This happens even when Help Links is
  disabled at that moment, so no data is left behind.

`occ user:setting` does not show lazy values. To look at a user's list in the
database:

```sql
SELECT configvalue FROM oc_preferences
WHERE userid = '<uid>' AND appid = 'helplinks' AND configkey = 'introvox_tours';
```

There is no need to clear it by hand: any change it depends on rebuilds it on
the next visit, and it expires after 24 hours.

### Large instances

The work for one Help visit depends on that user only, not on how many accounts
the instance has. A visit costs the same with 100 or 100,000 users.

Measured with `TourService::getForUser()`, 200 calls each, on Nextcloud 35 with
IntroVox 2.0.0, 4 tours and a user with local groups:

| Path | Time per call | Database |
|---|---|---|
| Stored list still current | 0.08 ms | one preference read |
| List rebuilt | 0.6 ms | one preference read and one write |
| Stored value | 377 bytes | one row in `oc_preferences` |

These are warm-cache numbers, not a load test. A fresh request adds one
indexed query that loads the user's lazy preferences (index
`prefs_uid_lazy_i`), plus the user's group lookup.

How this holds up at 10,000 or 100,000 users:

- **Lookups:** the preference is found through the primary key of
  `oc_preferences` (`userid`, `appid`, `configkey`), so lookups do not slow
  down as the table grows.
- **Rows:** there is at most one extra row per user who opens Help. Every
  account already has dozens of preferences, so this adds a few percent.
- **Size:** about 0.4–1.5 KB per user for a normal number of tours. That is
  roughly 40–150 MB with 100,000 Help visitors, and more with the 50 tours
  IntroVox allows at most.
- **Writes:** at most one per user per day, and only when that user visits.
  If all 100,000 users opened Help every day, that would average about 1.2
  writes per second.
- **Admin changes cause no spike.** A changed tour changes every user's hash,
  but each list is rebuilt only on that user's own next visit, so the work
  spreads out by itself.
- **The group lookup is the part to watch.** The hash includes the user's
  groups, so `getUserGroupIds()` runs on every Help visit, even when the stored
  list is still current. With LDAP or another remote group backend this costs
  milliseconds instead of microseconds. Core and the LDAP backend cache it, and
  both core and IntroVox already look up the same groups on every page.
- **It is small next to IntroVox itself.** On every page view, IntroVox checks
  every tour for the user (`LoadScripts::runtimeState()`, through
  `TourStateService::due()` and `TourRepository::availableTo()`). Help Links
  does the same check at most once a day per Help visitor.

A daily background job would scale worse on exactly these points. With 100,000
accounts it would rebuild 100,000 lists every night: a burst of 100,000 writes
and 100,000 group lookups. On LDAP that is a flood of queries every night, and
its cost would grow with the number of accounts rather than with use.

## Where everything is stored

| What | Where |
|---|---|
| Help sections | `oc_helplinks_sections` |
| Sub-links | `oc_helplinks_sublinks` |
| Support settings | `oc_appconfig`, app `helplinks`: `support_email`, `support_url`, `environment_name` |
| IntroVox tours per user | `oc_preferences`, app `helplinks`, key `introvox_tours` |

The table `oc_helplinks_settings` is created by the first migration but is not
used.

## Routes

**Web routes**, used by the app's own pages; they need a logged-in user and
the request token:

| Route | Who | Purpose |
|---|---|---|
| `GET /apps/helplinks/` | any user | the Help page |
| `GET /apps/helplinks/api/sections` | any user | everything the Help page shows, including `introvoxTours` |
| `POST/PUT/DELETE /apps/helplinks/api/sections…`, `POST …/api/sections/reorder` | admin | edit help sections |
| `POST /apps/helplinks/api/settings` | admin | save the support settings |

**OCS API** (`/ocs/v2.php/apps/helplinks/api/v1/…`):

| Route | Who |
|---|---|
| `GET sections`, `GET sections/{id}`, `GET settings` | any user |
| `POST sections`, `PUT sections/{id}`, `DELETE sections/{id}`, `PUT settings` | admin |
