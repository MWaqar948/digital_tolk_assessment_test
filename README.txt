I looked into the code and Repository pattern is being used which is good to make Controller cleaner but BookingRepository was very massive 
and it was handling Jobs and Notifications which are irrelevant but now I have applied SOLID principles and cleaned the Booking Repository by 
moving the Jobs related code to it's own JobRepository and created a Notification Service for handling notifications . And we should hide low level
details and use Abstraction which makes code more scalable, open to extension and closed to modification like I used interfaces which make 
implementation switchable. I also cleaned BookingController by moving Distance feeding related business logic to Job Service.