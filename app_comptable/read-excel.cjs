const xlsx = require('xlsx');
const path = require('path');

const workbook = xlsx.readFile(path.join(__dirname, '../MES LICENCE 1 2026-2027.xlsx'));
const sheetName = workbook.SheetNames[0];
const sheet = workbook.Sheets[sheetName];
const data = xlsx.utils.sheet_to_json(sheet);

console.log("Headers:");
if (data.length > 0) {
  console.log(Object.keys(data[0]));
}
console.log("\nFirst 3 rows:");
console.log(JSON.stringify(data.slice(0, 3), null, 2));
