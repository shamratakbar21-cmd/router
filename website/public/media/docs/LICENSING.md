# Licensing and activation boundaries

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


## Development candidate

**Commercial activation is not qualified. dev.14 removes the historical public `nur-lic-test` fixture verification key and ships an empty verification-key set. No commercial signing trust chain is provisioned.** No commercial signing private key, paid plan offer, purchase cart or public support commitment is supplied. Do not use these development images for commercial enforcement. A production licensing deployment needs a separately secured private signing key, a deliberately provisioned trust chain and corresponding qualification; it cannot retroactively change a frozen image.

## Implemented control plane

WinControl provides authenticated licensing administration, hashed activation keys, device-bound Ed25519 envelopes, policy limits, online/offline workflows and audit records. Admin/license-admin/operator permissions apply according to the implemented route and policy. Activation endpoints remain authenticated and rate limited; this public website does not expose a privileged activation API.

The plan table lists policy ceilings, not prices, support commitments or demonstrated capacity. Issued activation keys are secrets: store only their hashes and reveal plaintext once. A `device_id` field alone is not proof of hardware ownership or anti-cloning. Cloning an installed disk can clone identity and license state.

Private signing material stays only on a trusted management/licensing host. Expired/revoked/unverified licensing blocks entitlement-gated new mutations while preserving forwarding and local recovery. Offline revocation propagation has documented limits; it is not an immediate remote kill switch. Never tear down established subscribers as a side effect of licensing.

Historical lab activation/enforcement tests and present application tests do not qualify commercial key provisioning, customer activation or production deployment. `LICENSING.md` in source describes product behavior; it is not a source copyright license. See [source terms](/docs/source).
