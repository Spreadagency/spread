/* Spread AI — خطة الحملات (المرحلة 4): الحملة في الباقة بتتحسب لما العميل يعتمد الخطة.
 * لو الكتابة رجعت not_approved ← نسأل العميل مرة ← «اعتمد الخطة» ← نكمّل نفس الطلب. (الواجهة الكاملة في المرحلة 5) */
(function () {
  if (!window.SpreadAPI || SpreadAPI.__caPatched) return;
  var orig = SpreadAPI.post, declined = {}, asking = {};
  SpreadAPI.post = async function (ep, a, d, o) {
    var r = await orig.call(SpreadAPI, ep, a, d, o);
    if (ep !== 'campaign-flow' || a !== 'content_generate' || !r || r.code !== 'not_approved' || !d || !d.id) return r;
    if (declined[d.id]) return r;
    if (!asking[d.id]) {
      asking[d.id] = (async function () {
        if (!confirm('نعتمد الخطة دي؟\nالحملة هتتحسب من حملات باقتك، والمنشورات وتصميم كل منشور من حد الحملة.')) { declined[d.id] = true; return false; }
        var ap = await orig.call(SpreadAPI, ep, 'plan_approve', { id: d.id });
        if (!ap.ok) { alert(ap.error || 'مقدرناش نعتمد الخطة'); declined[d.id] = true; return false; }
        return true;
      })();
    }
    var ok = await asking[d.id];
    return ok ? orig.call(SpreadAPI, ep, a, d, o) : r;
  };
  SpreadAPI.__caPatched = true;
})();
