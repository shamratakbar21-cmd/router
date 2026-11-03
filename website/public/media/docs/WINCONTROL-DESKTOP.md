# WinControl desktop and real ARP workflows

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


## Workspace

Open an authenticated router workspace. Core implemented modules share compact navigation, flat toolbars, tables and dialogs. Multiple windows independently move, resize, minimize to the taskbar, maximize/restore, close, focus and stack. Use keyboard controls as labelled in the interface; visible unsupported entries do not enable placeholder operations.

Save a named workspace for the current router/user. Open panels, geometry and window states are stored in this browser. Reload restores them. A different router or authenticated user uses a separate preference scope. This is not account-wide or cross-browser synchronization. Resize/reorder columns, hide/show them in Columns, and reset preferences if needed. Live refresh retains selection, scroll, focus and unsaved editor/caret state.

## ARP

The IPv4 ARP panel displays actual Linux neighbor states. Unknown VRF, bridge-port or hostname data remains unavailable; NUR observed/static/disabled flags are not RouterOS compatibility flags. Dynamic neighbors are observed, while supported mutations manage static neighbor definitions. A disabled managed definition is retained in desired configuration but is not enforced as an active permanent neighbor; a dynamically learned neighbor may still appear. Disabling does not disable the interface or prohibit all kernel learning.

Editing requires the appliance to advertise the required neighbor operations and pass capability checks. Add/edit/delete, explicit enable/disable and comments stage revisions, participate in audit and reboot persistence, and apply through Safe Mode. Inspect the diff and revision, apply, check actual observed state and only then confirm. An unconfirmed or failed apply follows the independent recovery path. Never infer success from a toolbar click.

## Captures and limitations

[Real screenshots](/screenshots) were captured at 2048×1088 and 100% zoom. They align compact hierarchy, fonts, toolbars, columns, selection, dialogs and status bars with the supplied Winbox reference, without claiming a pixel-identical implementation or RouterOS protocol equivalence. The original third-party reference image is not redistributed.

WiFi, Switch, Mesh, MPLS, Dot1X, general Terminal and appliance diagnostics remain unsupported. Core editor tests do not assert production forwarding capacity.
