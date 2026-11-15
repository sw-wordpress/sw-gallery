import { deflateSync } from 'node:zlib';
import { chunk } from './galleries';

/**
 * A valid PNG that looks a little like a landscape photo (sky gradient, hills, a sun, film grain; palette and layout
 * follow the seed): review screenshots show how shards crop real content. Keep the width <= 700 to stay small.
 */
export function photoPng(seed: number, width: number, height: number): Buffer {
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0);
  header.writeUInt32BE(height, 4);
  header[8] = 8;
  header[9] = 2;
  let state = Math.imul(seed + 1, 2654435761) >>> 0 || 1;
  const random = (): number => {
    state = (state ^ (state << 13)) >>> 0;
    state = (state ^ (state >>> 17)) >>> 0;
    state = (state ^ (state << 5)) >>> 0;
    return state / 4294967296;
  };
  const hue = random();
  const palette = (h: number, s: number, l: number): [number, number, number] => {
    const k = (n: number): number => (n + h * 12) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = (n: number): number =>
      Math.round(255 * (l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)))));
    return [f(0), f(8), f(4)];
  };
  const skyTop = palette(hue, 0.55, 0.62);
  const skyLow = palette(hue + 0.08, 0.7, 0.85);
  const far = palette(hue + 0.5, 0.25, 0.42);
  const near = palette(hue + 0.55, 0.35, 0.24);
  const sunX = (0.2 + random() * 0.6) * width;
  const sunY = (0.18 + random() * 0.2) * height;
  const sunR = Math.min(width, height) * (0.06 + random() * 0.05);
  const phase1 = random() * 6.28;
  const phase2 = random() * 6.28;
  const stride = width * 3 + 1;
  const raw = Buffer.alloc(stride * height);
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const u = x / width;
      const v = y / height;
      const hill1 = 0.62 + 0.07 * Math.sin(u * 5 + phase1) + 0.03 * Math.sin(u * 13 + phase2);
      const hill2 = 0.8 + 0.06 * Math.sin(u * 3 + phase2);
      let px: [number, number, number];
      if (v > hill2) px = near;
      else if (v > hill1) px = far;
      else {
        const d = Math.hypot(x - sunX, y - sunY);
        const glow = Math.max(0, 1 - d / (sunR * 4));
        const t = v / hill1;
        px = [0, 1, 2].map((c) => {
          const base = (skyTop[c] as number) * (1 - t) + (skyLow[c] as number) * t;
          return d < sunR ? 255 : Math.min(255, base + glow * glow * 90);
        }) as [number, number, number];
      }
      const grain = (random() - 0.5) * 22;
      for (let c = 0; c < 3; c += 1) {
        raw[y * stride + 1 + x * 3 + c] = Math.max(
          0,
          Math.min(255, Math.round((px[c] as number) + grain)),
        );
      }
    }
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', header),
    chunk('IDAT', deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}
