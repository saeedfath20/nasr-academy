package com.nasr.academy;

import org.json.JSONObject;
import java.io.*;
import java.nio.charset.StandardCharsets;
import java.security.*;
import java.security.spec.X509EncodedKeySpec;
import java.util.*;
import java.util.zip.*;

/** Stores application CODE only. Session vaults and learning data are never touched. */
public final class UpdateEngine {
    private static final long MAX_ZIP=20L*1024*1024, MAX_EXPANDED=50L*1024*1024;
    private final File root;
    private final String publicKey, abi, appId;
    private final long bundled, binary;
    private final Properties state=new Properties();

    public static final class Release {
        public final long sequence,size;
        public final String version,hash,envelope;
        public final Map<String,String> files=new TreeMap<>();
        Release(long sequence,long size,String version,String hash,String envelope){this.sequence=sequence;this.size=size;this.version=version;this.hash=hash;this.envelope=envelope;}
        String directory(){return "b"+sequence+"-"+hash.substring(0,12);}
    }
    public UpdateEngine(File root,String publicKey,String abi,String appId,long bundled,long binary) throws Exception {
        this.root=root.getCanonicalFile();this.publicKey=publicKey;this.abi=abi;this.appId=appId;this.bundled=bundled;this.binary=binary;
        if(!root.isDirectory()&&!root.mkdirs())throw new IOException("Update storage unavailable");
        File file=new File(root,"state.properties");
        if(file.isFile())try(InputStream in=new FileInputStream(file)){state.load(in);}catch(IOException ignored){state.clear();}
    }
    private long number(String key){try{return Long.parseLong(state.getProperty(key,"0"));}catch(Exception e){return 0;}}
    private String value(String key){return state.getProperty(key,"");}
    private void save() throws Exception {
        File temp=new File(root,"state.next");
        try(FileOutputStream out=new FileOutputStream(temp)){state.store(out,"Nasr interface updates");out.getFD().sync();}
        if(!temp.renameTo(new File(root,"state.properties")))throw new IOException("Atomic state commit failed");
    }
    private static byte[] hex(String s) throws Exception {
        if(s==null||s.length()%2!=0||!s.matches("[a-fA-F0-9]+"))throw new IOException("Invalid hex");
        byte[] result=new byte[s.length()/2];for(int i=0;i<result.length;i++)result[i]=(byte)Integer.parseInt(s.substring(i*2,i*2+2),16);return result;
    }
    private static String digest(InputStream in,long limit) throws Exception {
        MessageDigest hash=MessageDigest.getInstance("SHA-256");byte[] buffer=new byte[16384];long total=0;int n;
        while((n=in.read(buffer))!=-1){total+=n;if(total>limit)throw new IOException("File exceeds limit");hash.update(buffer,0,n);}
        StringBuilder out=new StringBuilder();for(byte b:hash.digest())out.append(String.format(Locale.ROOT,"%02x",b&255));return out.toString();
    }
    private static String path(String name) throws Exception {
        if(name==null||name.length()>240||!name.matches("[A-Za-z0-9_@+./-]+")||name.startsWith("/")||name.contains("\\"))throw new IOException("Unsafe bundle path");
        for(String part:name.split("/",-1))if(part.isEmpty()||part.equals(".")||part.equals(".."))throw new IOException("Unsafe bundle path");return name;
    }
    public Release verify(String envelope) throws Exception {
        if(envelope.length()>262144)throw new IOException("Manifest exceeds limit");
        JSONObject outer=new JSONObject(envelope);String payload=outer.getString("payload");
        Signature signature=Signature.getInstance("SHA256withRSA");
        signature.initVerify(KeyFactory.getInstance("RSA").generatePublic(new X509EncodedKeySpec(hex(publicKey))));
        signature.update(payload.getBytes(StandardCharsets.UTF_8));
        if(!signature.verify(hex(outer.getString("signature"))))throw new SecurityException("Release signature invalid");
        JSONObject data=new JSONObject(payload);
        if(data.getInt("schema")!=1||data.getInt("protocol")!=1||!abi.equals(data.getString("shellAbi"))||!appId.equals(data.getString("appId")))throw new SecurityException("Release incompatible with installed shell");
        long seq=data.getLong("sequence"),size=data.getLong("size");String hash=data.getString("sha256"),version=data.getString("version");
        if(seq<1||size<1||size>MAX_ZIP||!hash.matches("[a-f0-9]{64}")||!version.matches("[0-9]+\\.[0-9]+\\.[0-9]+"))throw new IOException("Release metadata invalid");
        Release result=new Release(seq,size,version,hash,envelope);JSONObject files=data.getJSONObject("files");
        if(files.length()<1||files.length()>300)throw new IOException("Invalid file count");
        Iterator<String> keys=files.keys();while(keys.hasNext()){String name=keys.next(),sum=files.getString(name);path(name);if(!sum.matches("[a-f0-9]{64}"))throw new IOException("Invalid digest");result.files.put(name,sum);}
        if(!result.files.containsKey("index.html")||!result.files.containsKey("app-auth.js")||!result.files.containsKey("core/portal.js"))throw new IOException("Incomplete interface bundle");
        return result;
    }
    private File directory(String name) throws Exception {
        if(!name.matches("b[0-9]+-[a-f0-9]{12}"))throw new IOException("Invalid stored bundle");
        File dir=new File(root,name).getCanonicalFile();if(!dir.getParentFile().equals(root))throw new IOException("Unsafe storage path");return dir;
    }
    private Release stored(String name,boolean verifyFiles) throws Exception {
        File dir=directory(name),manifest=new File(dir,"release.json");
        if(manifest.length()>262144)throw new IOException("Invalid stored manifest");
        byte[] bytes=read(new FileInputStream(manifest),262144);Release release=verify(new String(bytes,StandardCharsets.UTF_8));
        if(!release.directory().equals(name))throw new IOException("Stored release mismatch");
        if(verifyFiles)for(Map.Entry<String,String> item:release.files.entrySet()){
            File file=new File(new File(dir,"www"),item.getKey());
            try(InputStream in=new FileInputStream(file)){if(!item.getValue().equals(digest(in,MAX_EXPANDED)))throw new IOException("Stored interface corrupted");}
        }
        return release;
    }
    public static byte[] read(InputStream source,long max) throws Exception {
        try(InputStream in=source;ByteArrayOutputStream out=new ByteArrayOutputStream()){
            byte[] buffer=new byte[8192];int n;long total=0;while((n=in.read(buffer))!=-1){total+=n;if(total>max)throw new IOException("Response exceeds limit");out.write(buffer,0,n);}return out.toByteArray();
        }
    }
    public synchronized boolean needs(Release release) {
        return release.sequence>Math.max(bundled,Math.max(number("highest"),number("blocked")));
    }
    /** A downloaded update can only become active on a subsequent Activity launch. */
    public synchronized File launch() throws Exception {
        if(number("binary")!=binary){long highest=Math.max(number("highest"),bundled),blocked=number("blocked");state.clear();state.setProperty("binary",Long.toString(binary));state.setProperty("highest",Long.toString(highest));state.setProperty("blocked",Long.toString(blocked));save();}
        if(!value("trial").isEmpty())rollback();
        String pending=value("pending");
        if(!pending.isEmpty()){
            try{
                Release release=stored(pending,true);
                if(release.sequence<=bundled||release.sequence<=number("blocked"))throw new IOException("Obsolete candidate");
                state.setProperty("good",value("active"));state.setProperty("active",pending);state.setProperty("trial",pending);
            }catch(Exception invalid){try{state.setProperty("blocked",Long.toString(Math.max(number("blocked"),stored(pending,false).sequence)));}catch(Exception ignored){} }
            state.remove("pending");save();
        }
        if(!value("active").isEmpty())try{stored(value("active"),true);return new File(directory(value("active")),"www");}catch(Exception invalid){rollback();if(!value("active").isEmpty()){stored(value("active"),true);return new File(directory(value("active")),"www");}}
        return null;
    }
    public synchronized boolean trial(){return !value("trial").isEmpty();}
    public synchronized void ready() throws Exception {
        if(trial()){state.setProperty("good",value("active"));state.remove("trial");save();}
    }
    public synchronized void rollback() throws Exception {
        try{state.setProperty("blocked",Long.toString(Math.max(number("blocked"),stored(value("active"),false).sequence)));}catch(Exception ignored){}
        String fallback=value("good");
        if(fallback.equals(value("active")))fallback="";
        try{if(!fallback.isEmpty())stored(fallback,true);}catch(Exception invalid){fallback="";}
        state.setProperty("active",fallback);state.remove("trial");state.remove("pending");save();
    }
    public synchronized String version(String bundledVersion){try{return stored(value("active"),false).version;}catch(Exception e){return bundledVersion;}}
    public synchronized void stage(Release release,InputStream input) throws Exception {
        if(!needs(release)){input.close();return;}
        File work=new File(root,"stage-"+UUID.randomUUID()),zip=new File(work,"bundle.zip"),www=new File(work,"www");
        if(!www.mkdirs())throw new IOException("Cannot prepare update");
        try{
            try(InputStream in=input;FileOutputStream out=new FileOutputStream(zip)){
                byte[] buffer=new byte[16384];long total=0;int n;while((n=in.read(buffer))!=-1){total+=n;if(total>release.size)throw new IOException("Bundle length mismatch");out.write(buffer,0,n);}if(total!=release.size)throw new IOException("Truncated bundle");out.getFD().sync();
            }
            try(InputStream in=new FileInputStream(zip)){if(!release.hash.equals(digest(in,MAX_ZIP)))throw new SecurityException("Bundle digest mismatch");}
            Set<String> seen=new HashSet<>();long expanded=0;
            try(ZipInputStream in=new ZipInputStream(new FileInputStream(zip))){
                ZipEntry entry;byte[] buffer=new byte[16384];
                while((entry=in.getNextEntry())!=null){
                    String name=entry.getName();if(entry.isDirectory()){path(name.endsWith("/")?name.substring(0,name.length()-1):name);continue;}
                    path(name);if(!release.files.containsKey(name)||!seen.add(name))throw new IOException("Unexpected or duplicate file");
                    File file=new File(www,name);if(!file.getCanonicalPath().startsWith(www.getCanonicalPath()+File.separator))throw new IOException("Path traversal");
                    File parent=file.getParentFile();if(!parent.isDirectory()&&!parent.mkdirs())throw new IOException("Cannot create bundle folder");
                    try(FileOutputStream out=new FileOutputStream(file)){int n;while((n=in.read(buffer))!=-1){expanded+=n;if(expanded>MAX_EXPANDED)throw new IOException("Expanded bundle exceeds limit");out.write(buffer,0,n);}}
                    try(InputStream check=new FileInputStream(file)){if(!release.files.get(name).equals(digest(check,MAX_EXPANDED)))throw new SecurityException("Extracted file digest mismatch");}
                }
            }
            if(seen.size()!=release.files.size())throw new IOException("Missing interface files");
            try(FileOutputStream out=new FileOutputStream(new File(work,"release.json"))){out.write(release.envelope.getBytes(StandardCharsets.UTF_8));out.getFD().sync();}
            if(!zip.delete())throw new IOException("Cannot finalize update");
            File dest=directory(release.directory());if(dest.exists())remove(dest);
            if(!work.renameTo(dest))throw new IOException("Cannot atomically activate candidate");
            state.setProperty("pending",release.directory());state.setProperty("highest",Long.toString(release.sequence));save();clean();
        }finally{if(work.exists())remove(work);}
    }
    private void remove(File file) throws Exception {
        if(!file.getCanonicalPath().startsWith(root.getPath()+File.separator))throw new IOException("Refusing delete outside updater storage");
        if(file.isDirectory()){File[] children=file.listFiles();if(children!=null)for(File child:children)remove(child);}
        if(file.exists()&&!file.delete())throw new IOException("Cannot clean update storage");
    }
    private void clean() throws Exception {
        Set<String> keep=new HashSet<>(Arrays.asList(value("active"),value("good"),value("pending"),value("trial")));
        File[] children=root.listFiles();if(children!=null)for(File child:children)if(child.isDirectory()&&!keep.contains(child.getName())&&(child.getName().matches("b[0-9]+-[a-f0-9]{12}")||child.getName().startsWith("stage-")))remove(child);
    }
}
