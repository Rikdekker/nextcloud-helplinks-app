# Nextcloud Helplinks App

App that allows you to create a list of links to external documentation.

![HelplinksUserInterface.png](https://github.com/sara-nl/nextcloud-helplinks-app/raw/main/docs/screenshots/HelplinksUserInterface.png)

![HelplinksAdminInterface.png](https://github.com/sara-nl/nextcloud-helplinks-app/raw/main/docs/screenshots/HelplinksAdminInterface.png)

### Helplinks

You can add help sections yourself via the Nextcloud Admin interface.

A section consists of a Section Title with a Description and a main link with a title.  
In addition, you can specify multiple Sub-links within the section, consisting of a title and a URL.

This allows you to easily create a clear help page yourself.

### Introvox Interactive Tutorial

The app can be combined with the Introvox Interactive Tutorial. When IntroVox is enabled for a user, the Help page lists the tours that user may get, each with a link that starts it. The list is kept per user and refreshed at most once a day, only when that user opens Help; there is no background job. The Introvox Interactive tutorial can be found in the Nextcloud App Store.

### Supportdesk

In addition to a reference to your wiki and the Introvox Interactive Tutorial, you can also add a reference to a service desk email address or service desk URL if the user requires further support.

Set these under **Administration settings > Additional settings > IT Support Settings**, or with occ:

```
occ config:app:set helplinks support_email --value=helpdesk@surf.nl
occ config:app:set helplinks support_url --value=https://servicedesk.surf.nl
```

See [docs/HowItWorks.md](docs/HowItWorks.md) for how the app works and where it stores things.

