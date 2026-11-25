import com.nasr.academy.UpdateEngine;
import org.json.JSONObject;
import java.io.*;import java.nio.file.*;import java.nio.charset.StandardCharsets;
public class UpdateEngineTest {
 static int checks=0;static File data,root,fixtures;static String key,abi;
 static String text(File f)throws Exception{return new String(Files.readAllBytes(f.toPath()),StandardCharsets.UTF_8);}
 static void ok(boolean b,String label){if(!b)throw new AssertionError(label);checks++;}
 static UpdateEngine engine(long binary)throws Exception{return new UpdateEngine(root,key,abi,"com.nasr.academy",1006000,binary);}
 static UpdateEngine.Release release(UpdateEngine e,String n)throws Exception{return e.verify(text(new File(fixtures,n+".json")));}
 static void stage(UpdateEngine e,String n)throws Exception{e.stage(release(e,n),new FileInputStream(new File(fixtures,n+".zip")));}
 interface Task{void run()throws Exception;}
 static void reject(Task t,String label)throws Exception{boolean failed=false;try{t.run();}catch(Exception e){failed=true;}ok(failed,label);}
 public static void main(String[]args)throws Exception{
  fixtures=new File(args[0]);data=new File(args[1]);root=new File(data,"updates");
  JSONObject trust=new JSONObject(text(new File(fixtures,"trust.json")));key=trust.getString("key");abi=trust.getString("abi");data.mkdirs();File vault=new File(data,"nasr-session-vault");Files.write(vault.toPath(),"OPAQUE-EXISTING-SESSION".getBytes(StandardCharsets.UTF_8));
  UpdateEngine first=engine(160);ok(first.launch()==null,"fresh shell uses bundled interface");stage(first,"next");ok(!first.needs(release(first,"next")),"repeated download rejected");
  UpdateEngine next=engine(160);ok(next.launch()!=null&&next.trial(),"candidate starts on subsequent launch");next.ready();ok(!next.trial(),"successful launch confirmed");
  UpdateEngine stable=engine(160);ok(stable.launch()!=null&&stable.version("").equals("1.6.1"),"verified update persists across restart");
  stage(stable,"later");UpdateEngine bad=engine(160);ok(bad.launch()!=null&&bad.trial(),"second candidate trial");
  UpdateEngine recovered=engine(160);ok(recovered.launch()!=null&&recovered.version("").equals("1.6.1"),"unconfirmed launch rolls back to prior interface");ok(!recovered.needs(release(recovered,"later")),"failed release not offered again");
  reject(()->release(recovered,"incompatible"),"native-incompatible bundle rejected");reject(()->release(recovered,"traversal"),"path traversal rejected");
  JSONObject modified=new JSONObject(text(new File(fixtures,"next.json")));modified.put("payload",modified.getString("payload").replace("1.6.1","9.9.9"));reject(()->recovered.verify(modified.toString()),"forged manifest signature rejected");
  reject(()->recovered.verify("<!doctype html><h1>Server error</h1>"),"HTML endpoint error rejected");
  UpdateEngine.Release later=release(recovered,"missing");byte[] truncated=Files.readAllBytes(new File(fixtures,"missing.zip").toPath());reject(()->recovered.stage(later,new ByteArrayInputStream(java.util.Arrays.copyOf(truncated,truncated.length-7))),"partial download rejected");
  byte[] tampered=truncated.clone();tampered[tampered.length/2]^=1;reject(()->recovered.stage(later,new ByteArrayInputStream(tampered)),"tampered archive rejected");
  reject(()->stage(recovered,"missing"),"missing file bundle rejected");ok(engine(160).launch()!=null,"failed staging preserves active interface");
  UpdateEngine upgraded=engine(161);ok(upgraded.launch()==null,"native binary update resets code bundle only");ok(!upgraded.needs(release(upgraded,"later")),"native update preserves replay protection");ok(text(vault).equals("OPAQUE-EXISTING-SESSION"),"existing session file untouched throughout upgrades and rollbacks");
  System.out.println("PASS "+checks+" actual JVM signature, archive, state and session-preservation checks");
 }
}
