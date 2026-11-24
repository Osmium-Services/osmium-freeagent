---
title: Push paid orders to FreeAgent
tags: freeagent, accounting, invoice, orders, bookkeeping, oauth, push
page: admin/settings/freeagent
---

**FreeAgent** connects your shop to your FreeAgent account. A **Push to FreeAgent** button on each paid order creates a sales invoice there. It creates an open invoice.

## Set it up

1. In FreeAgent, create an app for your shop and note its ****OAuth identifier** and **OAuth secret****.
2. Open **FreeAgent Settings** and enter them. The **Redirect URI** shown must match the one on the FreeAgent app exactly, including the protocol and trailing slash.
3. Choose the **Sales category** from the list loaded from FreeAgent.
4. Tick **Use the FreeAgent sandbox** only for testing with a sandbox app and account. Disconnect and reconnect after changing it.
5. The secret is stored on the server and never shown again.
6. Enter the **Allowed company**: the exact name of the FreeAgent company that invoices may go to.
7. Press **Save Settings**, then connect. You are sent to FreeAgent to approve the connection.

Pushes are refused until the allowed company matches the connected one exactly, so an order can never go to the wrong account.

## Push an order

Open a paid order and press **Push to FreeAgent**. The invoice is created as open in FreeAgent. Only a paid order can be pushed, and it cannot be undone from here.
