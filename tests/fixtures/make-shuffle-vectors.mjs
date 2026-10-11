// Generates tests/fixtures/shuffle-vectors.json from the REAL Node implementation, so the PHP port
// is checked against the source of truth, not against a re-implementation.
//   node --experimental-strip-types tests/fixtures/make-shuffle-vectors.mjs ../state-wars-api
import { writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

const nodeRepo = resolve(process.argv[2] ?? "../state-wars-api");
const { prng, permutation } = await import(pathToFileURL(resolve(nodeRepo, "src/core/random.ts")).href);

// Same formula as src/modules/battles/scoring.ts (that file imports random.js, which strip-types can't resolve).
const optionOrder = (seed, index) => permutation(4, (seed + (index + 1) * 7919) >>> 0);

const seeds = [0, 1, 2, 42, 99, 7919, 65535, 65536, 2 ** 31 - 1, 2 ** 31 - 2, 1234567890, 2147480000];
for (let i = 0; i < 988; i++) seeds.push(Math.floor(((i * 2654435761) % 2 ** 31)));

const vectors = seeds.map((seed) => {
  const r = prng(seed);
  return {
    seed,
    prng: Array.from({ length: 5 }, () => r()),
    questions10: permutation(10, seed),
    questions7: permutation(7, seed),
    options: Array.from({ length: 10 }, (_, i) => optionOrder(seed, i)),
  };
});

const out = resolve(import.meta.dirname, "shuffle-vectors.json");
writeFileSync(out, JSON.stringify(vectors));
console.log(`wrote ${vectors.length} vectors to ${out}`);
