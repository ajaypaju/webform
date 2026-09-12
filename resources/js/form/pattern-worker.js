// Runs customer regexes off the main thread so a pathological pattern can be abandoned (patterns.js: 50 ms budget).
// JS has no backtrack limit; this is the client-side counterpart of SafePattern's pcre.backtrack_limit (I8).
self.onmessage = ({ data: { id, pattern, value } }) => {
  let ok;

  try {
    ok = new RegExp(pattern, 'u').test(value);
  } catch {
    ok = false;
  }

  self.postMessage({ id, ok });
};
