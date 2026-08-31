using System;
using System.Collections.Generic;
using System.Drawing;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Runtime.Serialization;
using System.Runtime.Serialization.Json;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace DruryITSupport {
    [DataContract] public class Settings { [DataMember] public string SupportEndpoint; [DataMember] public string ClientName; [DataMember] public string ClientId; [DataMember] public string DeploymentToken; }
    [DataContract] public class Ticket { [DataMember(Name="clientName")] public string ClientName; [DataMember(Name="clientId")] public string ClientId; [DataMember(Name="computerName")] public string Computer; [DataMember(Name="userName")] public string User; [DataMember(Name="windowsVersion")] public string Windows; [DataMember(Name="problem")] public string Problem; [DataMember(Name="priority")] public string Priority; [DataMember(Name="bestTime")] public string BestTime; [DataMember(Name="submittedAtLocal")] public string Submitted; }
    [DataContract] public class TicketResult { [DataMember(Name="ticketId")] public string TicketId; }
    static class Program {
        [STAThread] static void Main(string[] args) { Application.EnableVisualStyles(); Application.SetCompatibleTextRenderingDefault(false); Application.Run(new TrayContext(args.Length > 0 && args[0] == "--open")); }
        public static string AppDir { get { return Path.GetDirectoryName(Application.ExecutablePath); } }
        public static Settings LoadSettings() { using (var s=File.OpenRead(Path.Combine(AppDir,"appsettings.json"))) return (Settings)new DataContractJsonSerializer(typeof(Settings)).ReadObject(s); }
        public static Image Logo() { var stream=typeof(Program).Assembly.GetManifestResourceStream("druryit-logo.jpg"); return stream == null ? null : Image.FromStream(stream); }
    }
    sealed class TrayContext : ApplicationContext {
        readonly NotifyIcon tray; SupportForm form;
        public TrayContext(bool open) {
            var menu=new ContextMenuStrip(); menu.Items.Add("Open DruryIT Support",null,(s,e)=>ShowForm()); menu.Items.Add("Exit",null,(s,e)=>{ tray.Visible=false; ExitThread(); });
            tray=new NotifyIcon { Icon=Icon.ExtractAssociatedIcon(Application.ExecutablePath), Text="DruryIT Support", ContextMenuStrip=menu, Visible=true };
            tray.DoubleClick += (s,e)=>ShowForm(); if(open) { var timer=new Timer{Interval=250}; timer.Tick+=(s,e)=>{timer.Stop();timer.Dispose();ShowForm();};timer.Start(); }
        }
        void ShowForm() { if(form==null || form.IsDisposed) form=new SupportForm(); form.Show(); form.WindowState=FormWindowState.Normal; form.Activate(); }
    }
    sealed class SupportForm : Form {
        readonly Settings settings; readonly TextBox problem=new TextBox(); readonly ComboBox priority=new ComboBox(); readonly ComboBox bestTime=new ComboBox(); readonly Label files=new Label(); readonly List<string> attachments=new List<string>(); readonly Button send=new Button();
        public SupportForm() {
            settings=Program.LoadSettings(); Text="DruryIT Support"; StartPosition=FormStartPosition.CenterScreen; Size=new Size(830,730); MinimumSize=new Size(760,680); BackColor=Color.FromArgb(247,250,253); Font=new Font("Segoe UI",10); Icon=Icon.ExtractAssociatedIcon(Application.ExecutablePath);
            var logo=new PictureBox{Location=new Point(28,18),Size=new Size(170,120),SizeMode=PictureBoxSizeMode.Zoom,Image=Program.Logo()}; Controls.Add(logo);
            AddLabel("Tell James what's happening.",new Point(215,34),20,FontStyle.Bold,Color.FromArgb(18,45,76)); AddLabel("Describe the problem in your own words. Screenshots are optional but helpful.",new Point(218,78),10,FontStyle.Regular,Color.DimGray);
            AddLabel("What's wrong?",new Point(30,145),11,FontStyle.Bold,Color.Black); problem.Location=new Point(30,174);problem.Size=new Size(745,170);problem.Multiline=true;problem.ScrollBars=ScrollBars.Vertical;Controls.Add(problem);
            AddLabel("How urgent is it?",new Point(30,365),10,FontStyle.Regular,Color.Black); priority.Location=new Point(30,390);priority.Size=new Size(345,30);priority.DropDownStyle=ComboBoxStyle.DropDownList;priority.Items.AddRange(new object[]{"Normal - when you get a chance","Important - affecting my work","Urgent - I cannot work"});priority.SelectedIndex=0;Controls.Add(priority);
            AddLabel("Best time to connect",new Point(405,365),10,FontStyle.Regular,Color.Black); bestTime.Location=new Point(405,390);bestTime.Size=new Size(370,30);bestTime.DropDownStyle=ComboBoxStyle.DropDownList;bestTime.Items.AddRange(new object[]{"Any time","Now","This morning","This afternoon","After 4 PM"});bestTime.SelectedIndex=0;Controls.Add(bestTime);
            AddLabel("Screenshots / attachments",new Point(30,450),11,FontStyle.Bold,Color.Black); AddButton("Capture Screen",30,480,145,(s,e)=>CaptureScreen()); AddButton("Paste Screenshot",185,480,160,(s,e)=>Paste()); AddButton("Add File",355,480,115,(s,e)=>AddFiles()); AddButton("Clear",480,480,100,(s,e)=>{attachments.Clear();RefreshFiles();});
            files.Location=new Point(30,530);files.Size=new Size(745,38);files.ForeColor=Color.DimGray;Controls.Add(files);RefreshFiles();
            AddLabel("This request identifies: "+settings.ClientName+" | "+Environment.MachineName+" | "+Environment.UserName,new Point(30,580),10,FontStyle.Regular,Color.DimGray);
            send.Text="Send to DruryIT";send.Location=new Point(30,615);send.Size=new Size(230,48);send.BackColor=Color.FromArgb(24,93,156);send.ForeColor=Color.White;send.FlatStyle=FlatStyle.Flat;send.Font=new Font("Segoe UI",11,FontStyle.Bold);send.Click+=async(s,e)=>await Send();Controls.Add(send);
        }
        void AddLabel(string text,Point location,float size,FontStyle style,Color color){var l=new Label{Text=text,Location=location,AutoSize=true,Font=new Font("Segoe UI",size,style),ForeColor=color};Controls.Add(l);} void AddButton(string text,int x,int y,int width,EventHandler click){var b=new Button{Text=text,Location=new Point(x,y),Size=new Size(width,38)};b.Click+=click;Controls.Add(b);}
        void RefreshFiles(){files.Text=attachments.Count==0?"No screenshots or files attached.":"Attached: "+string.Join(", ",attachments.ConvertAll(Path.GetFileName));}
        string TempPng(){var d=Path.Combine(Path.GetTempPath(),"DruryITSupport");Directory.CreateDirectory(d);return Path.Combine(d,"screenshot-"+DateTime.Now.ToString("yyyyMMdd-HHmmss-fff")+".png");}
        void CaptureScreen(){try{var b=Screen.PrimaryScreen.Bounds;using(var bmp=new Bitmap(b.Width,b.Height))using(var g=Graphics.FromImage(bmp)){g.CopyFromScreen(b.Location,Point.Empty,b.Size);var p=TempPng();bmp.Save(p);attachments.Add(p);RefreshFiles();}}catch(Exception ex){MessageBox.Show(ex.Message,"Screenshot failed");}}
        void Paste(){try{if(!Clipboard.ContainsImage()){MessageBox.Show("No image is on the clipboard. Use Windows + Shift + S first, then click Paste Screenshot.","DruryIT Support");return;}using(var image=Clipboard.GetImage()){var p=TempPng();image.Save(p);attachments.Add(p);RefreshFiles();}}catch(Exception ex){MessageBox.Show(ex.Message,"Paste failed");}}
        void AddFiles(){using(var d=new OpenFileDialog{Multiselect=true,Filter="Images and documents|*.png;*.jpg;*.jpeg;*.gif;*.pdf;*.txt;*.log|All files|*.*"})if(d.ShowDialog()==DialogResult.OK){attachments.AddRange(d.FileNames);RefreshFiles();}}
        static string Json<T>(T value){using(var s=new MemoryStream()){new DataContractJsonSerializer(typeof(T)).WriteObject(s,value);return System.Text.Encoding.UTF8.GetString(s.ToArray());}}
        static T Parse<T>(string value){using(var s=new MemoryStream(System.Text.Encoding.UTF8.GetBytes(value)))return (T)new DataContractJsonSerializer(typeof(T)).ReadObject(s);}
        async Task Send(){if(string.IsNullOrWhiteSpace(problem.Text)){MessageBox.Show("Please tell James what is happening before sending.","DruryIT Support");return;}send.Enabled=false;send.Text="Sending...";try{var t=new Ticket{ClientName=settings.ClientName,ClientId=settings.ClientId,Computer=Environment.MachineName,User=Environment.UserName,Windows=Environment.OSVersion.VersionString,Problem=problem.Text.Trim(),Priority=priority.SelectedItem.ToString(),BestTime=bestTime.SelectedItem.ToString(),Submitted=DateTimeOffset.Now.ToString("O")};using(var client=new HttpClient{Timeout=TimeSpan.FromSeconds(40)})using(var multipart=new MultipartFormDataContent()){client.DefaultRequestHeaders.Authorization=new AuthenticationHeaderValue("Bearer",settings.DeploymentToken);multipart.Add(new StringContent(Json(t),System.Text.Encoding.UTF8,"application/json"),"ticket");foreach(var path in attachments){var f=new FileInfo(path);if(!f.Exists)continue;if(f.Length>8*1024*1024)throw new Exception("Attachment "+f.Name+" is larger than 8 MB.");multipart.Add(new StreamContent(File.OpenRead(path)),"attachments[]",f.Name);}var response=await client.PostAsync(settings.SupportEndpoint,multipart);var body=await response.Content.ReadAsStringAsync();if(!response.IsSuccessStatusCode)throw new Exception("Server returned "+(int)response.StatusCode+": "+body);var result=Parse<TicketResult>(body);MessageBox.Show("Your request was sent to DruryIT.\r\n\r\nTicket: "+result.TicketId,"Request Sent");Close();}}catch(Exception ex){MessageBox.Show("The request could not be sent. Your description is still on this screen.\r\n\r\n"+ex.Message,"DruryIT Support");send.Enabled=true;send.Text="Send to DruryIT";}}
    }
}
