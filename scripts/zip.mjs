import fs from 'node:fs/promises';
import path from 'node:path';
import {deflateRawSync} from 'node:zlib';
const table=Array.from({length:256},(_,n)=>{for(let k=0;k<8;k++)n=(n&1)?0xedb88320^(n>>>1):n>>>1;return n>>>0;});
const crc32=data=>{let n=0xffffffff;for(const b of data)n=table[(n^b)&255]^(n>>>8);return (n^0xffffffff)>>>0;};
export async function tree(root,prefix='',filter=()=>true){
 const entries=[];
 async function visit(dir,relative){for(const item of (await fs.readdir(dir,{withFileTypes:true})).sort((a,b)=>a.name.localeCompare(b.name))){const rel=relative+item.name;if(!filter(rel,item))continue;if(item.isSymbolicLink())continue;if(item.isDirectory())await visit(path.join(dir,item.name),rel+'/');else if(item.isFile())entries.push({name:prefix+rel,data:await fs.readFile(path.join(dir,item.name))});}}
 await visit(root,'');return entries;
}
export function zip(entries){
 const local=[],central=[];let offset=0;
 for(const entry of entries){
  if(entry.name.startsWith('/')||entry.name.split('/').some(x=>['','..','.'].includes(x))||entry.name.includes('\\'))throw new Error('Unsafe ZIP path');
  const name=Buffer.from(entry.name),data=entry.data,packed=deflateRawSync(data,{level:9}),crc=crc32(data);
  const head=Buffer.alloc(30);head.writeUInt32LE(0x04034b50);head.writeUInt16LE(20,4);head.writeUInt16LE(0x800,6);head.writeUInt16LE(8,8);head.writeUInt16LE(33,12);head.writeUInt32LE(crc,14);head.writeUInt32LE(packed.length,18);head.writeUInt32LE(data.length,22);head.writeUInt16LE(name.length,26);local.push(head,name,packed);
  const item=Buffer.alloc(46);item.writeUInt32LE(0x02014b50);item.writeUInt16LE(20,4);item.writeUInt16LE(20,6);item.writeUInt16LE(0x800,8);item.writeUInt16LE(8,10);item.writeUInt16LE(33,14);item.writeUInt32LE(crc,16);item.writeUInt32LE(packed.length,20);item.writeUInt32LE(data.length,24);item.writeUInt16LE(name.length,28);item.writeUInt32LE(offset,42);central.push(item,name);offset+=head.length+name.length+packed.length;
 }
 const directory=Buffer.concat(central),end=Buffer.alloc(22);end.writeUInt32LE(0x06054b50);end.writeUInt16LE(entries.length,8);end.writeUInt16LE(entries.length,10);end.writeUInt32LE(directory.length,12);end.writeUInt32LE(offset,16);return Buffer.concat([...local,directory,end]);
}
