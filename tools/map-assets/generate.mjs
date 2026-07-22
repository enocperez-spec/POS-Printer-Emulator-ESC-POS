import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { geoAlbersUsa, geoNaturalEarth1, geoPath } from 'd3-geo';
import countries from 'i18n-iso-countries';
import { feature } from 'topojson-client';
import usTopology from 'us-atlas/states-10m.json' with { type: 'json' };
import worldTopology from 'world-atlas/countries-110m.json' with { type: 'json' };

const directory = path.dirname(fileURLToPath(import.meta.url));
const outputDirectory = path.resolve(directory, '../../admin-website/assets/maps');

const stateCodes = {
  '01':'al','02':'ak','04':'az','05':'ar','06':'ca','08':'co','09':'ct','10':'de','11':'dc','12':'fl',
  '13':'ga','15':'hi','16':'id','17':'il','18':'in','19':'ia','20':'ks','21':'ky','22':'la','23':'me',
  '24':'md','25':'ma','26':'mi','27':'mn','28':'ms','29':'mo','30':'mt','31':'ne','32':'nv','33':'nh',
  '34':'nj','35':'nm','36':'ny','37':'nc','38':'nd','39':'oh','40':'ok','41':'or','42':'pa','44':'ri',
  '45':'sc','46':'sd','47':'tn','48':'tx','49':'ut','50':'vt','51':'va','53':'wa','54':'wv','55':'wi','56':'wy'
};

const stateNames = new Intl.DisplayNames(['en'], { type: 'region' });
const usa = feature(usTopology, usTopology.objects.states);
const usaProjection = geoAlbersUsa().fitSize([1000, 620], usa);
const usaPath = geoPath(usaProjection);
const usaLocations = usa.features.flatMap((item) => {
  const id = stateCodes[String(item.id).padStart(2, '0')];
  if (!id) return [];
  const pathData = usaPath(item);
  if (!pathData) return [];
  return [{ id, name: item.properties.name || stateNames.of(`US-${id.toUpperCase()}`) || id.toUpperCase(), path: pathData }];
});

const world = feature(worldTopology, worldTopology.objects.countries);
const worldProjection = geoNaturalEarth1().fitSize([1000, 520], world);
const worldPath = geoPath(worldProjection);
const worldLocations = world.features.flatMap((item) => {
  const numeric = String(item.id).padStart(3, '0');
  const id = (countries.numericToAlpha2(numeric) || (numeric === '010' ? 'AQ' : '')).toLowerCase();
  const pathData = worldPath(item);
  if (!id || !pathData) return [];
  return [{ id, name: item.properties.name || id.toUpperCase(), path: pathData }];
});

fs.mkdirSync(outputDirectory, { recursive: true });
writeMap('world.js', { label: 'World geographic usage', viewBox: '0 0 1000 520', locations: worldLocations });
writeMap('usa.js', { label: 'United States geographic usage', viewBox: '0 0 1000 620', locations: usaLocations });

function writeMap(fileName, map) {
  fs.writeFileSync(path.join(outputDirectory, fileName), `export default ${JSON.stringify(map)};\n`, 'utf8');
}
